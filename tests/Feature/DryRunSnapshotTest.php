<?php

declare(strict_types=1);

use Kalimera\KalimeraInstaller;
use Kalimera\Tests\Fakes\FakeExecutableFinder;
use Kalimera\Tests\Fakes\FakePortChecker;
use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * Runs the installer with every collaborator faked. The scoped error handler mutes
 * the @-suppressed warnings (missing .env probes) that Pest would otherwise report.
 *
 * @param  list<string>  $argv
 */
function runFakeInstaller(FakeProcessRunner $processRunner, array $argv): int
{
    $kalimeraInstaller = new KalimeraInstaller(
        executableFinder: new FakeExecutableFinder,
        portChecker: new FakePortChecker,
        processRunner: $processRunner,
    );

    set_error_handler(fn (): bool => true);

    try {
        return $kalimeraInstaller->execute($argv);
    } finally {
        restore_error_handler();
    }
}

it('produces the full default dry-run command sequence', function (): void {
    $processRunner = new FakeProcessRunner(dryRun: true);

    $exitCode = runFakeInstaller($processRunner, ['new', tempDir().'/demo-app', '--dry-run', '--defaults']);

    expect($exitCode)->toBe(0)
        // Questions get asked during a rehearsal — they change nothing, and their answers
        // are what it reports. Side effects do not: this list staying empty is the promise.
        ->and($processRunner->quietCommands)->toBe([])
        // And the promise is only worth something if the plan reached the side effects at
        // all. These are the two the run asked for and did not get — an empty list above
        // with an empty list here would just mean the steps never got that far.
        ->and(array_map(fn (array $entry): array => $entry['command'], $processRunner->skippedQuietCommands))->toBe([
            ['./vendor/bin/sail', 'down', '-v'],
            ['docker', 'compose', 'exec', '-T', '-u', 'root', 'laravel.test', 'chown', '-R', 'sail', '/home/sail'],
        ])
        ->and(array_map(fn (array $entry): array => $entry['command'], $processRunner->probeCommands))->toBe([
            ['docker', 'info'],
            ['docker', 'container', 'inspect', 'demo-app-laravel.test-1'],
            ['docker', 'container', 'inspect', 'demo-app-pgsql-1'],
            ['docker', 'container', 'inspect', 'demo-app-redis-1'],
            ['docker', 'volume', 'inspect', 'demo-app_sail-pgsql'],
            ['docker', 'volume', 'inspect', 'demo-app_sail-redis'],
        ])
        ->and(array_map(fn (array $entry): array => $entry['command'], $processRunner->commands))->toBe([
            ['laravel', 'new', 'demo-app', '--pest', '--git', '--no-boost', '--no-interaction', '--react'],
            ['php', 'artisan', 'sail:install', '--with=pgsql,redis', '--no-interaction'],
            ['./vendor/bin/sail', 'up', '-d', '--wait'],
            ['./vendor/bin/sail', 'composer', 'require', 'php:^8.5', '--no-update', '--no-interaction'],
            ['./vendor/bin/sail', 'composer', 'require', 'laravel/horizon'],
            ['./vendor/bin/sail', 'artisan', 'horizon:install'],
            ['./vendor/bin/sail', 'composer', 'require', 'laravel/fortify'],
            ['./vendor/bin/sail', 'artisan', 'fortify:install'],
            ['./vendor/bin/sail', 'composer', 'require', 'laravel/ai'],
            ['./vendor/bin/sail', 'composer', 'require', 'laravel/nightwatch'],
            ['./vendor/bin/sail', 'composer', 'require', '--dev', 'laravel/pint', 'larastan/larastan', 'barryvdh/laravel-ide-helper', 'rector/rector', 'driftingly/rector-laravel'],
            ['./vendor/bin/sail', 'composer', 'require', 'laravel/boost', '--dev'],
            ['./vendor/bin/sail', 'artisan', 'boost:install', '--guidelines', '--skills', '--mcp', '--no-interaction'],
            ['./vendor/bin/sail', 'artisan', 'boost:update', '--no-discover', '--no-interaction'],
            ['./vendor/bin/sail', 'composer', 'dump-autoload'],
            ['./vendor/bin/sail', 'artisan', 'migrate', '--no-interaction'],
            ['./vendor/bin/sail', 'npm', 'install'],
            ['./vendor/bin/sail', 'composer', 'ide-helper'],
            ['./vendor/bin/sail', 'composer', 'rector:fix'],
            ['./vendor/bin/sail', 'composer', 'rector:fix'],
            ['./vendor/bin/sail', 'composer', 'pint:fix'],
            ['./vendor/bin/sail', 'composer', 'phpstan'],
            ['./vendor/bin/sail', 'composer', 'quality'],
            ['git', 'add', '-A'],
            ['git', 'commit', '-m', 'chore: scaffold application with kalimera'],
        ])
        ->and($processRunner->fileActions)->toBe([
            'normalize bootstrap/providers.php to inline class names',
            'gitignore the .kalimera.json and .kalimera-steps.json resume state',
            'save the chosen answers to .kalimera.json so --continue can reuse them',
            'remove the sqlite database left over from `laravel new`',
            'sync the DB_* block from .env to .env.example',
            'pin the compose file to the PHP 8.5 Sail runtime',
            'publish pint.json',
            'publish phpstan.neon.dist with an empty baseline (replaces the skeleton phpstan.neon)',
            'gitignore the generated ide-helper files',
            'publish rector.php',
            'add composer scripts: pint:dry, pint:fix, phpstan, phpstan-clear, ide-helper, rector:dry, rector:fix, quality',
            'preconfigure boost.json with agents: claude_code, cursor, codex',
            'create src/{Domain,Shared,Support,Feature,Infrastructure} with .gitkeep files',
            'map the Core\\ namespace to src/ in composer.json',
            'publish app/Providers/AgentGuardServiceProvider.php',
            'register AgentGuardServiceProvider in bootstrap/providers.php',
            'remove .kalimera.json — the scaffold completed, --continue is no longer needed',
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
        ->and($commands)->toContain(['php', 'artisan', 'sail:install', '--with=mysql', '--no-interaction'])
        ->and($commands)->toContain(['./vendor/bin/sail', 'composer', 'require', 'spatie/laravel-medialibrary'])
        ->and($commands)->toContain(['./vendor/bin/sail', 'composer', 'require', '--dev', 'barryvdh/laravel-debugbar'])
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
        ->and($printable)->not->toContain('require laravel/boost')
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
