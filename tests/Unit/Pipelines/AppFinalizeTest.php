<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\AppFinalize;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeAppFinalize(InstallerOption $installerOption, FakeProcessRunner $processRunner): AppFinalize
{
    return new AppFinalize(
        installerOption: $installerOption,
        processRunner: $processRunner,
        retryDelaySeconds: 0,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

function markGitInitialized(InstallerOption $installerOption): void
{
    mkdir(directory: $installerOption->targetPath.'/.git', permissions: 0755, recursive: true);
}

it('retries the migration and continues once the database is ready', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'artisan migrate', times: 2);
    markGitInitialized($installerOption);

    makeAppFinalize($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail npm install',
        'git add -A',
        'git commit -m chore: scaffold application with kalimera',
    ]);
});

it('recreates the database volume and migrates again when leftover data blocks the migration', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'artisan migrate', times: 3);
    markGitInitialized($installerOption);

    makeAppFinalize($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail down -v',
        './vendor/bin/sail up -d --wait',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail npm install',
        'git add -A',
        'git commit -m chore: scaffold application with kalimera',
    ]);
});

it('gives up when the migration keeps failing after the database was recreated', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('artisan migrate');

    expect(fn () => makeAppFinalize($installerOption, $processRunner)->execute())
        ->toThrow(CommandFailedException::class)
        ->and($processRunner->commandLines())->toContain('./vendor/bin/sail down -v')
        ->and($processRunner->commands)->toHaveCount(8);
});

it('never recreates a database volume when the application has no database service', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => [], 'sailServices' => ['redis']]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('artisan migrate');

    expect(fn () => makeAppFinalize($installerOption, $processRunner)->execute())
        ->toThrow(CommandFailedException::class)
        ->and($processRunner->commandLines())->not->toContain('./vendor/bin/sail down -v')
        ->and($processRunner->commands)->toHaveCount(3);
});

it('warns and continues when the quality gates fail', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => ['pint', 'phpstan', 'rector']]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('composer ide-helper');
    $processRunner->failOn('rector:fix');
    $processRunner->failOn('pint:fix');
    $processRunner->failOn('composer phpstan');
    $processRunner->failOn('composer quality');
    markGitInitialized($installerOption);

    makeAppFinalize($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail npm install',
        './vendor/bin/sail composer ide-helper',
        './vendor/bin/sail composer rector:fix',
        './vendor/bin/sail composer rector:fix',
        './vendor/bin/sail composer pint:fix',
        './vendor/bin/sail composer phpstan',
        './vendor/bin/sail php vendor/bin/phpstan analyse --generate-baseline=phpstan-baseline.neon --allow-empty-baseline --memory-limit=1G',
        './vendor/bin/sail composer quality',
        'git add -A',
        'git commit -m chore: scaffold application with kalimera',
    ]);
});

it('skips git init when a repository already exists', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    markGitInitialized($installerOption);

    makeAppFinalize($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->not->toContain('git init -b main');
});

it('initializes a git repository when none exists', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;

    makeAppFinalize($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toContain('git init -b main');
});
