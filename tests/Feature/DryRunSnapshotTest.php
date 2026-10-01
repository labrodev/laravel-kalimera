<?php

declare(strict_types=1);

use Kalimera\Services\ComposeProjectName;
use Kalimera\Tests\Fakes\FakeProcessRunner;

it('produces the full default dry-run command sequence', function (): void {
    $processRunner = new FakeProcessRunner(dryRun: true);
    $targetPath = tempDir().'/demo-app';
    $project = new ComposeProjectName($targetPath)->unique();

    $exitCode = runFakeInstaller($processRunner, ['new', $targetPath, '--dry-run', '--defaults']);

    expect($exitCode)->toBe(0)
        // Questions get asked during a rehearsal — they change nothing, and their answers
        // are what it reports. Side effects do not: this list staying empty is the promise.
        ->and($processRunner->quietCommands)->toBe([])
        // And the promise is only worth something if the plan reached the side effects at
        // all. These are the ones the run asked for and did not get — an empty list above
        // with an empty list here would just mean the steps never got that far.
        ->and(array_map(fn (array $entry): array => $entry['command'], $processRunner->skippedQuietCommands))->toBe([
            ['./vendor/bin/sail', 'down', '-v'],
            ['./vendor/bin/sail', 'rm', '-rf', '/tmp/rector_cached_files'],
        ])
        ->and(array_map(fn (array $entry): array => $entry['command'], $processRunner->probeCommands))->toBe([
            // The host's PHP and Composer versions, as `php -r` scripts whose exit code answers.
            ['php', '-r', 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'],
            ['php', '-r', 'preg_match("/(\\d+\\.\\d+\\.\\d+)/", (string) shell_exec("composer --version --no-ansi 2>/dev/null"), $m); exit(isset($m[1]) && version_compare($m[1], "2.2.0", ">=") ? 0 : 1);'],
            ['docker', 'info'],
            ['docker', 'container', 'inspect', $project.'-laravel.test-1'],
            ['docker', 'container', 'inspect', $project.'-pgsql-1'],
            ['docker', 'container', 'inspect', $project.'-redis-1'],
            ['docker', 'volume', 'inspect', $project.'_sail-pgsql'],
            ['docker', 'volume', 'inspect', $project.'_sail-redis'],
        ])
        // A question too: the local Sail image's exact PHP, which a rehearsal asks as well. The
        // fake has no image to answer, so the pin below stays at the bare minor.
        ->and(array_map(fn (array $entry): array => $entry['command'], $processRunner->askCommands))->toBe([
            ['docker', 'run', '--rm', '--pull=never', '--entrypoint', 'php', 'sail-8.5/app', '-r', 'echo PHP_VERSION;'],
        ])
        ->and(array_map(fn (array $entry): array => $entry['command'], $processRunner->commands))->toBe([
            ['laravel', 'new', 'demo-app', '--pest', '--git', '--no-boost', '--no-interaction', '--react'],
            ['env', 'DOCKER_HOST=unix:///nonexistent/kalimera.sock', 'php', 'artisan', 'sail:install', '--with=pgsql,redis', '--php=8.5', '--no-interaction'],
            // Host phase: every download, before any container exists.
            ['composer', 'require', '--no-scripts', '--no-plugins', '--ignore-platform-req=ext-*', '--no-interaction', '--', 'laravel/horizon', 'laravel/fortify', 'laravel/ai', 'laravel/scout'],
            ['composer', 'require', '--dev', '--no-scripts', '--no-plugins', '--ignore-platform-req=ext-*', '--no-interaction', '--', 'laravel/pint', 'larastan/larastan', 'barryvdh/laravel-ide-helper', 'rector/rector', 'driftingly/rector-laravel', 'laravel/boost', 'laravel/vet'],
            ['composer', 'require', '--no-scripts', '--no-plugins', '--ignore-platform-req=ext-*', '--no-interaction', '--', 'laravel/nightwatch'],
            // Container phase: everything that executes application or package code.
            ['./vendor/bin/sail', 'up', '-d', '--wait'],
            ['./vendor/bin/sail', 'php', 'vendor/bin/vet', '--init', '--no-interaction'],
            ['./vendor/bin/sail', 'composer', 'install', '--no-interaction'],
            ['./vendor/bin/sail', 'artisan', 'vendor:publish', '--tag=laravel-assets', '--force', '--no-interaction'],
            ['./vendor/bin/sail', 'artisan', 'horizon:install'],
            ['./vendor/bin/sail', 'artisan', 'fortify:install'],
            ['./vendor/bin/sail', 'artisan', 'vendor:publish', '--provider=Laravel\\Ai\\AiServiceProvider', '--no-interaction'],
            ['./vendor/bin/sail', 'artisan', 'vendor:publish', '--provider=Laravel\\Scout\\ScoutServiceProvider', '--no-interaction'],
            ['./vendor/bin/sail', 'artisan', 'boost:install', '--guidelines', '--skills', '--mcp', '--no-interaction'],
            ['./vendor/bin/sail', 'artisan', 'boost:update', '--no-discover', '--no-interaction'],
            ['./vendor/bin/sail', 'artisan', 'migrate', '--no-interaction'],
            ['./vendor/bin/sail', 'npm', 'install'],
            ['./vendor/bin/sail', 'composer', 'ide-helper'],
            ['./vendor/bin/sail', 'php', 'vendor/bin/rector', 'process', '--clear-cache'],
            ['./vendor/bin/sail', 'php', 'vendor/bin/rector', 'process', '--clear-cache'],
            ['./vendor/bin/sail', 'composer', 'pint:fix'],
            ['./vendor/bin/sail', 'composer', 'phpstan'],
            ['./vendor/bin/sail', 'composer', 'quality'],
            ['git', 'add', '-A'],
            ['git', 'commit', '-m', 'chore: scaffold application with kalimera'],
        ])
        ->and($processRunner->fileActions)->toBe([
            'normalize bootstrap/providers.php to inline class names',
            'gitignore the .kalimera.json resume state',
            'save the chosen answers to .kalimera.json so --continue can reuse them',
            'remove the sqlite database left over from `laravel new`',
            'sync the DB_* block from .env to .env.example',
            'require php ^8.5 and pin composer\'s platform to PHP 8.5',
            'publish pint.json',
            'publish phpstan.neon.dist (replaces the skeleton phpstan.neon)',
            'gitignore the generated ide-helper files',
            'publish rector.php',
            'add composer scripts: pint:dry, pint:fix, phpstan, phpstan-clear, ide-helper, rector:dry, rector:fix, quality',
            'allow the laravel/vet composer plugin, add the vet script and @vet to quality',
            'create src/{Domain,Shared,Support,Feature,Infrastructure} with .gitkeep files',
            'map the Core\\ namespace to src/ in composer.json',
            'publish app/Providers/AgentGuardServiceProvider.php',
            'register AgentGuardServiceProvider in bootstrap/providers.php',
            'set COMPOSE_PROJECT_NAME='.$project.' in .env so no other application shares its containers',
            'set SCOUT_DRIVER=database in .env and .env.example',
            'preconfigure boost.json with agents: claude_code, cursor, codex',
            'repair the published fortify, starter kit and horizon stubs that phpstan rejects',
        ]);
});

it('honours a config file for defaults and the additional-packages catalog', function (): void {
    $configPath = tempDir().'/kalimera.config.json';
    file_put_contents($configPath, json_encode([
        'preselected' => [
            'aroundPackages' => [],
            'boostAgents' => [],
            'coreNamespace' => null,
            'qualityTools' => [],
            'sailServices' => ['mysql'],
            'starterKit' => 'vue',
        ],
        'additionalPackages' => [
            ['package' => 'spatie/laravel-medialibrary', 'preselected' => true, 'publishProviders' => ['Spatie\\MediaLibrary\\MediaLibraryServiceProvider']],
            ['package' => 'barryvdh/laravel-debugbar', 'dev' => true, 'preselected' => true],
        ],
    ], JSON_THROW_ON_ERROR));

    $processRunner = new FakeProcessRunner(dryRun: true);

    $exitCode = runFakeInstaller($processRunner, ['new', tempDir().'/demo-app', '--dry-run', '--defaults', '--config='.$configPath]);

    $commands = array_map(fn (array $entry): array => $entry['command'], $processRunner->commands);

    expect($exitCode)->toBe(0)
        ->and($commands)->toContain(['laravel', 'new', 'demo-app', '--pest', '--git', '--no-boost', '--no-interaction', '--vue'])
        ->and($commands)->toContain(['env', 'DOCKER_HOST=unix:///nonexistent/kalimera.sock', 'php', 'artisan', 'sail:install', '--with=mysql', '--php=8.5', '--no-interaction'])
        ->and($commands)->toContain(['composer', 'require', '--no-scripts', '--no-plugins', '--ignore-platform-req=ext-*', '--no-interaction', '--', 'spatie/laravel-medialibrary'])
        ->and($commands)->toContain(['composer', 'require', '--dev', '--no-scripts', '--no-plugins', '--ignore-platform-req=ext-*', '--no-interaction', '--', 'laravel/boost', 'barryvdh/laravel-debugbar'])
        ->and($commands)->toContain(['./vendor/bin/sail', 'artisan', 'vendor:publish', '--provider=Spatie\\MediaLibrary\\MediaLibraryServiceProvider', '--no-interaction']);
});

// Boost was the one heavy step with no way to decline it, which left no lean plan for the
// nightly resume job to exercise the checkpoint machinery against — and no answer at all
// for anyone scaffolding without AI agents.
it('leaves Laravel Boost out of the plan entirely when it is declined', function (): void {
    $configPath = tempDir().'/kalimera.config.json';
    file_put_contents($configPath, json_encode([
        'preselected' => ['installBoost' => false],
    ], JSON_THROW_ON_ERROR));

    $processRunner = new FakeProcessRunner(dryRun: true);

    $exitCode = runFakeInstaller($processRunner, ['new', tempDir().'/demo-app', '--dry-run', '--defaults', '--config='.$configPath]);

    $printable = implode(' ', array_map(fn (array $entry): string => implode(' ', $entry['command']), $processRunner->commands));

    expect($exitCode)->toBe(0)
        // Not a bare 'boost' match: `laravel new` always passes --no-boost, since kalimera
        // installs Boost itself rather than letting the installer do it.
        ->and($printable)->not->toContain('laravel/boost')
        ->and($printable)->not->toContain('boost:install')
        ->and($printable)->not->toContain('boost:update')
        ->and($processRunner->fileActions)->not->toContain('preconfigure boost.json with agents: claude_code, cursor, codex')
        // The rest of the plan is untouched — this declines a step, it does not trim the run.
        ->and($printable)->toContain('artisan sail:install')
        ->and($printable)->toContain('git commit');
});

it('fails with a clear error for an invalid config file', function (): void {
    $configPath = tempDir().'/kalimera.config.json';
    file_put_contents($configPath, '{"preselected": {"starterKit": "angular"}}');

    $exitCode = runFakeInstaller(new FakeProcessRunner(dryRun: true), ['new', tempDir().'/demo-app', '--dry-run', '--defaults', '--config='.$configPath]);

    expect($exitCode)->toBe(1);
});

it('touches nothing on disk during a dry run', function (): void {
    $targetPath = tempDir().'/demo-app';
    $processRunner = new FakeProcessRunner(dryRun: true);

    runFakeInstaller($processRunner, ['new', $targetPath, '--dry-run', '--defaults']);

    expect(is_dir($targetPath))->toBeFalse();
});

it('prints usage and exits successfully without a command', function (): void {
    expect(runFakeInstaller(new FakeProcessRunner(dryRun: true), []))->toBe(0);
});

it('prints usage and exits successfully for the help command', function (): void {
    expect(runFakeInstaller(new FakeProcessRunner(dryRun: true), ['help']))->toBe(0);
});

it('fails for an unknown command', function (): void {
    expect(runFakeInstaller(new FakeProcessRunner(dryRun: true), ['definitely-not-a-command']))->toBe(1);
});

// Vet is picked at the quality-tools prompt but has nothing to do with QualityToolsInstall,
// so a run that wants only vet skips that step and still downloads vet and records the
// trust file.
it('installs vet on its own when no static analysis tool is selected', function (): void {
    $configPath = tempDir().'/kalimera.config.json';
    file_put_contents($configPath, json_encode([
        'preselected' => ['qualityTools' => ['vet']],
    ], JSON_THROW_ON_ERROR));

    $processRunner = new FakeProcessRunner(dryRun: true);

    $exitCode = runFakeInstaller($processRunner, ['new', tempDir().'/demo-app', '--dry-run', '--defaults', '--config='.$configPath]);

    $lines = $processRunner->commandLines();

    expect($exitCode)->toBe(0)
        ->and($lines)->toContain('composer require --dev --no-scripts --no-plugins --ignore-platform-req=ext-* --no-interaction -- laravel/boost laravel/vet')
        ->and($lines)->toContain('./vendor/bin/sail php vendor/bin/vet --init --no-interaction')
        ->and(implode(' ', $lines))->not->toContain('laravel/pint')
        ->and($processRunner->fileActions)->not->toContain('publish pint.json');
});
