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

const DUPLICATE_SEQUENCE = 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "pg_class_relname_nsp_index" DETAIL:  Key (relname, relnamespace)=(migrations_id_seq, 2200) already exists.';

it('recreates the database at once when leftover schema blocks the migration', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'artisan migrate', output: DUPLICATE_SEQUENCE, times: 1);
    markGitInitialized($installerOption);

    makeAppFinalize($installerOption, $processRunner)->execute();

    // No retry loop: waiting cannot clear schema that is already there, so the three
    // identical failures the old code produced are pure noise.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail down -v',
        './vendor/bin/sail up -d --wait',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail npm install',
        'git add -A',
        'git commit -m chore: scaffold application with kalimera',
    ])
        // Recreating a database volume unannounced is the kind of thing a reader needs the
        // reason for, and the reason differs per classification.
        ->and(promptOutput())->toContain('Migrations hit schema left over from an earlier run');
});

it('stops without retrying when leftover schema survives the database recreation', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'artisan migrate', output: DUPLICATE_SEQUENCE);

    expect(fn () => makeAppFinalize($installerOption, $processRunner)->execute())
        ->toThrow(CommandFailedException::class)
        ->and($processRunner->commandLines())->toBe([
            './vendor/bin/sail artisan migrate --no-interaction',
            './vendor/bin/sail down -v',
            './vendor/bin/sail up -d --wait',
            './vendor/bin/sail artisan migrate --no-interaction',
        ]);
});

it('stops at once for a failure the database itself answered with', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'artisan migrate', output: 'SQLSTATE[42601]: Syntax error at or near "creat"', times: 1);
    markGitInitialized($installerOption);

    // The wording matches nothing the classifier knows, but the server clearly replied.
    // A reply does not change on the second attempt, and it does not change against an
    // empty database either — so neither the retries nor the volume recreation can help,
    // and destroying the database to prove it would only bury the syntax error.
    expect(fn () => makeAppFinalize($installerOption, $processRunner)->execute())
        ->toThrow(CommandFailedException::class)
        ->and($processRunner->commandLines())->toBe([
            './vendor/bin/sail artisan migrate --no-interaction',
        ]);
});

it('still retries while the database is unreachable', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(
        needle: 'artisan migrate',
        output: 'SQLSTATE[08006] [7] FATAL:  the database system is starting up',
        times: 2,
    );
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

it('gives up when the migration keeps failing after the database was recreated', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('artisan migrate');

    expect(fn () => makeAppFinalize($installerOption, $processRunner)->execute())
        ->toThrow(CommandFailedException::class)
        ->and($processRunner->commandLines())->toContain('./vendor/bin/sail down -v')
        ->and($processRunner->commands)->toHaveCount(8)
        // A failure with nothing to classify is read as "never got through", so this is the
        // arm a silent migrate lands on.
        ->and(promptOutput())->toContain('The database never became reachable');
});

// The retry budget exists for a database that has not finished booting, and a wrapper
// around the runner that throws before any command reported anything — the manifest guard,
// a file write — carries no output to classify. Reading that as "the server answered and
// refused" would spend the budget on the one case it was reserved for.
it('treats a failure that carried no command output as a database that is not up yet', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => []]);
    $processRunner = new FakeProcessRunner;
    $attempts = 0;

    $processRunner->onCommand('artisan migrate', function () use (&$attempts): void {
        $attempts++;

        if ($attempts <= 2) {
            throw new RuntimeException('composer.json could not be written');
        }
    });

    markGitInitialized($installerOption);

    makeAppFinalize($installerOption, $processRunner)->execute();

    // Three migrate attempts and no volume recreation. Classifying this as Rejected would
    // stop after the first and go straight to `down -v`.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail artisan migrate --no-interaction',
        './vendor/bin/sail npm install',
        'git add -A',
        'git commit -m chore: scaffold application with kalimera',
    ]);
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
