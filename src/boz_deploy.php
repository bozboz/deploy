<?php
namespace Deployer;

require 'recipe/common.php';

set('current_path', function () {
    return get('project_path');
});
set('release_path', function () {
    return get('project_path');
});
// Hosts
localhost()->roles('build');
foreach ($remotes ?? [] as $name => $remote) {
    host($name)
        ->stage($name)
        ->hostname($remote['host'])
        ->user($remote['user'])
        ->port($remote['port'])
        ->forwardAgent()
        ->multiplexing()
        ->sshOptions([
            'StrictHostKeyChecking' => 'no',
        ])
        ->set('deploy_path', "~/")
        ->set('project_path', "{{deploy_path}}{$remote['remote_path']}")
        ->set('bin/php', $remote['php'] ?? 'php')
//        ->set('bin/composer', $remote['php'] ?? '{{bin/php}} /user/bin/composer')
        ->set('branch', $remote['branch']);
}

set('composer_options', '{{composer_action}} --verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader --no-scripts');

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', true);

// Tasks

task('deploy:prepare', function () {
    run("cd {{deploy_path}} && if [ ! -d .dep ]; then mkdir .dep; fi");
});

task('upload', function () {
    foreach (get('sync_assets', []) as $from => $to) {
        upload($from, $to);
    }
});

task('deploy:update_code', function () {
    $repository = get('repository');
    $branch = get('branch');
    $git = get('bin/git');

    // If option `tag` is set
    if (input()->hasOption('tag')) {
        $commit = input()->getOption('tag');
    }
    run("cd {{project_path}} && [ -d .git ] || $git init");
    run("cd {{project_path}} && $git config remote.origin.url >&- || $git remote add origin $repository");
    run("cd {{project_path}} && $git fetch");
    if (isset($commit)) {
        run("cd {{project_path}} && $git reset --hard $commit");
    } else {
        run("cd {{project_path}} && $git reset --hard origin/$branch");
    }
});

/**
 * Main task
 */
desc('Deploy your project');
task('deploy', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    // 'artisan:down',
    'deploy:update_code',
    'deploy:vendors',
    'artisan:migrate',
    'artisan:db:seed',
    'artisan:view:cache',
    'artisan:optimize',
    'upload',
    // 'artisan:up',
    'deploy:unlock',
]);

after('deploy', 'success');

/**
 * Helper tasks
 */
desc('Disable maintenance mode');
task('artisan:up', function () {
    $output = run('if [ -f {{release_path}}/artisan ]; then {{bin/php}} {{release_path}}/artisan up; fi');
    writeln('<info>' . $output . '</info>');
});

desc('Enable maintenance mode');
task('artisan:down', function () {
    $output = run('if [ -f {{release_path}}/artisan ]; then {{bin/php}} {{release_path}}/artisan down; fi');
    writeln('<info>' . $output . '</info>');
});

desc('Execute artisan migrate');
task('artisan:migrate', function () {
    run('{{bin/php}} {{release_path}}/artisan migrate --force');
})->once();

desc('Execute artisan migrate:fresh');
task('artisan:migrate:fresh', function () {
    run('{{bin/php}} {{release_path}}/artisan migrate:fresh --force');
});

desc('Execute artisan migrate:rollback');
task('artisan:migrate:rollback', function () {
    $output = run('{{bin/php}} {{release_path}}/artisan migrate:rollback --force');
    writeln('<info>' . $output . '</info>');
});

desc('Execute artisan migrate:status');
task('artisan:migrate:status', function () {
    $output = run('{{bin/php}} {{release_path}}/artisan migrate:status');
    writeln('<info>' . $output . '</info>');
});

desc('Execute artisan db:seed');
task('artisan:db:seed', function () {
    if (!get('seed_class')) {
        return;
    }
    $output = run('{{bin/php}} {{release_path}}/artisan db:seed --force --class={{seed_class}}');
    writeln('<info>' . $output . '</info>');
});

desc('Execute artisan cache:clear');
task('artisan:cache:clear', function () {
    run('{{bin/php}} {{release_path}}/artisan cache:clear');
});

desc('Execute artisan config:cache');
task('artisan:config:cache', function () {
    run('{{bin/php}} {{release_path}}/artisan config:cache');
});

desc('Execute artisan route:cache');
task('artisan:route:cache', function () {
    run('{{bin/php}} {{release_path}}/artisan route:cache');
});

desc('Execute artisan view:clear');
task('artisan:view:clear', function () {
    run('{{bin/php}} {{release_path}}/artisan view:clear');
});

set('laravel_version', function () {
    $result = run('cd {{release_path}} && {{bin/php}} artisan --version');

    preg_match_all('/(\d+\.?)+/', $result, $matches);

    $version = $matches[0][0] ?? 5.5;

    return $version;
});

desc('Execute artisan view:cache');
task('artisan:view:cache', function () {
    $needsVersion = 5.6;
    $currentVersion = get('laravel_version');

    if (version_compare($currentVersion, $needsVersion, '>=')) {
        run('{{bin/php}} {{release_path}}/artisan view:cache');
    }
});

desc('Execute artisan event:cache');
task('artisan:event:cache', function () {
    $needsVersion = '5.8.9';
    $currentVersion = get('laravel_version');

    if (version_compare($currentVersion, $needsVersion, '>=')) {
        run('{{bin/php}} {{release_path}}/artisan event:cache');
    }
});

desc('Execute artisan event:clear');
task('artisan:event:clear', function () {
    $needsVersion = '5.8.9';
    $currentVersion = get('laravel_version');

    if (version_compare($currentVersion, $needsVersion, '>=')) {
        run('{{bin/php}} {{release_path}}/artisan event:clear');
    }
});

desc('Execute artisan optimize');
task('artisan:optimize', function () {
    $deprecatedVersion = 5.5;
    $readdedInVersion = 5.7;
    $currentVersion = get('laravel_version');

    if (
        version_compare($currentVersion, $deprecatedVersion, '<') ||
        version_compare($currentVersion, $readdedInVersion, '>=')
    ) {
        run('{{bin/php}} {{release_path}}/artisan optimize');
    }
});

desc('Execute artisan optimize:clear');
task('artisan:optimize:clear', function () {
    $needsVersion = 5.7;
    $currentVersion = get('laravel_version');

    if (version_compare($currentVersion, $needsVersion, '>=')) {
        run('{{bin/php}} {{release_path}}/artisan optimize:clear');
    }
});

desc('Execute artisan queue:restart');
task('artisan:queue:restart', function () {
    run('{{bin/php}} {{release_path}}/artisan queue:restart');
});
