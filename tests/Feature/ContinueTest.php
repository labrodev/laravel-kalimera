<?php

declare(strict_types=1);

use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * A run that dies partway through, leaving the checkpoint and the saved answers behind.
 */
function runUntilItFails(string $targetPath, string $failOn): FakeProcessRunner
{
    $processRunner = new FakeProcessRunner;
    $processRunner->onCommand('laravel new', fn () => scaffoldFakeApp($targetPath));
    $processRunner->failOn($failOn);

    expect(runFakeInstaller($processRunner, ['new', $targetPath, '--defaults']))->toBe(1);

    return $processRunner;
}

it('records the steps that finished and drops the record once the scaffold completes', function (): void {
    $targetPath = tempDir().'/demo-app';

    runUntilItFails(targetPath: $targetPath, failOn: 'require laravel/boost');

    $recorded = json_decode((string) file_get_contents($targetPath.'/.kalimera.json'), true)['completedSteps'];

    expect($recorded)->toBe([
        'AppCreate',
        'SailInstall',
        'SailRuntimeConfigure',
        'SailPortsConfigure',
        'SailStart',
        'PhpConstraintApply',
        'AroundPackagesInstall',
        'QualityToolsInstall',
    ]);

    $resumed = new FakeProcessRunner;

    expect(runFakeInstaller($resumed, ['new', $targetPath, '--defaults', '--continue']))->toBe(0)
        ->and(file_exists($targetPath.'/.kalimera.json'))->toBeFalse();
});

it('does not replay the steps an earlier run finished', function (): void {
    $targetPath = tempDir().'/demo-app';

    runUntilItFails(targetPath: $targetPath, failOn: 'require laravel/boost');

    $resumed = new FakeProcessRunner;
    runFakeInstaller($resumed, ['new', $targetPath, '--defaults', '--continue']);

    $lines = $resumed->commandLines();

    expect($lines)->not->toContain('laravel new demo-app --pest --git --no-boost --no-interaction --react')
        ->and($lines)->not->toContain('php artisan sail:install --with=pgsql,redis --no-interaction')
        ->and($lines)->not->toContain('./vendor/bin/sail composer require laravel/horizon')
        ->and($lines)->not->toContain('./vendor/bin/sail composer require --dev laravel/pint larastan/larastan barryvdh/laravel-ide-helper rector/rector driftingly/rector-laravel');
});

it('picks up at the step that failed and runs the rest of the plan', function (): void {
    $targetPath = tempDir().'/demo-app';

    runUntilItFails(targetPath: $targetPath, failOn: 'require laravel/boost');

    $resumed = new FakeProcessRunner;
    runFakeInstaller($resumed, ['new', $targetPath, '--defaults', '--continue']);

    expect($resumed->commandLines())->toBe([
        './vendor/bin/sail up -d --wait',
        './vendor/bin/sail composer require laravel/boost --dev',
        './vendor/bin/sail artisan boost:install --guidelines --skills --mcp --no-interaction',
        './vendor/bin/sail artisan boost:update --no-discover --no-interaction',
        './vendor/bin/sail composer dump-autoload',
        './vendor/bin/sail composer require laravel/vet --dev',
        './vendor/bin/sail php vendor/bin/vet --init --no-interaction',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail npm install',
        './vendor/bin/sail composer ide-helper',
        './vendor/bin/sail php vendor/bin/rector process --clear-cache',
        './vendor/bin/sail php vendor/bin/rector process --clear-cache',
        './vendor/bin/sail composer pint:fix',
        './vendor/bin/sail composer phpstan',
        './vendor/bin/sail composer quality',
        'git init -b main',
        'git add -A',
        'git commit -m chore: scaffold application with kalimera',
    ]);
});

it('starts the containers again even though an earlier run already did', function (): void {
    $targetPath = tempDir().'/demo-app';

    runUntilItFails(targetPath: $targetPath, failOn: 'require laravel/boost');

    $resumed = new FakeProcessRunner;
    runFakeInstaller($resumed, ['new', $targetPath, '--defaults', '--continue']);

    // A checkpoint proves the containers were started once, not that they are up now.
    expect($resumed->commandLines())->toContain('./vendor/bin/sail up -d --wait');
});

it('keeps a config edited between the failed run and the resume', function (): void {
    $targetPath = tempDir().'/demo-app';

    runUntilItFails(targetPath: $targetPath, failOn: 'require laravel/boost');

    file_put_contents($targetPath.'/pint.json', '{"preset": "edited while debugging"}');

    runFakeInstaller(new FakeProcessRunner, ['new', $targetPath, '--defaults', '--continue']);

    expect(file_get_contents($targetPath.'/pint.json'))->toBe('{"preset": "edited while debugging"}')
        ->and(file_exists($targetPath.'/pint.json.bak'))->toBeFalse();
});

it('backs the edit up rather than losing it when the failed step has to republish', function (): void {
    $targetPath = tempDir().'/demo-app';

    // Failing inside the quality step means it is not checkpointed, so the resume
    // re-runs it — and republishing is a copy over whatever is there.
    runUntilItFails(targetPath: $targetPath, failOn: 'larastan/larastan');

    file_put_contents($targetPath.'/pint.json', '{"preset": "edited while debugging"}');

    runFakeInstaller(new FakeProcessRunner, ['new', $targetPath, '--defaults', '--continue']);

    expect(file_get_contents($targetPath.'/pint.json.bak'))->toBe('{"preset": "edited while debugging"}');
});

it('keeps the resume state out of the initial commit', function (): void {
    $targetPath = tempDir().'/demo-app';

    runUntilItFails(targetPath: $targetPath, failOn: 'require laravel/boost');

    expect(file_get_contents($targetPath.'/.gitignore'))->toContain('/.kalimera.json');
});

it('refuses to resume when the saved answers were lost', function (): void {
    $targetPath = tempDir().'/demo-app';

    runUntilItFails(targetPath: $targetPath, failOn: 'require laravel/boost');

    $state = json_decode((string) file_get_contents($targetPath.'/.kalimera.json'), true);
    unset($state['answers']);
    file_put_contents($targetPath.'/.kalimera.json', json_encode($state));

    $resumed = new FakeProcessRunner;

    // --defaults would otherwise rebuild the plan from whatever the config says today and
    // skip the steps that ran under the answers that were lost.
    expect(runFakeInstaller($resumed, ['new', $targetPath, '--defaults', '--continue']))->toBe(1)
        ->and($resumed->commandLines())->toBe([]);
});
