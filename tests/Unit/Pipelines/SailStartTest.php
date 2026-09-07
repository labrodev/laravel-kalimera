<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\SailStart;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeSailStart(InstallerOption $installerOption, FakeProcessRunner $processRunner): SailStart
{
    return new SailStart(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

/**
 * @return list<string>
 */
function quietLines(FakeProcessRunner $processRunner): array
{
    return array_map(
        fn (array $entry): string => implode(' ', $entry['command']),
        $processRunner->quietCommands,
    );
}

/**
 * Docker holds nothing under this project name — the state a fresh scaffold is in unless
 * the name was used before.
 */
function withNothingInherited(FakeProcessRunner $processRunner): FakeProcessRunner
{
    $processRunner->quietResult(needle: 'inspect', result: false);

    return $processRunner;
}

it('starts the containers with a single command when nothing conflicts', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = withNothingInherited(new FakeProcessRunner);

    makeSailStart($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe(['./vendor/bin/sail up -d --wait'])
        ->and(quietLines($processRunner))->toBe([
            'docker compose exec -T -u root laravel.test chown -R sail /home/sail',
        ]);
});

// Both halves matter. That nothing was performed is the promise; that the fixup was still
// asked for is what makes the first half evidence rather than an accident of the fake —
// an empty performed-list would also be produced by a step that never reached the call.
it('asks for the container home-directory fixup but performs nothing during a dry run', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner(dryRun: true);

    makeSailStart($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe(['./vendor/bin/sail up -d --wait'])
        ->and($processRunner->quietCommands)->toBe([])
        ->and(array_map(fn (array $entry): array => $entry['command'], $processRunner->skippedQuietCommands))->toBe([
            // The probes answer that a project of this name exists, so the rehearsal gets
            // as far as asking for the removal — and is refused. Printing what it would
            // have destroyed is the point of a dry run reaching this call at all.
            ['./vendor/bin/sail', 'down', '-v'],
            ['docker', 'compose', 'exec', '-T', '-u', 'root', 'laravel.test', 'chown', '-R', 'sail', '/home/sail'],
        ]);
});

it('removes leftover containers and retries when the first start fails', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = withNothingInherited(new FakeProcessRunner);
    $processRunner->failOn('up -d --wait', times: 1);

    makeSailStart($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail up -d --wait',
        './vendor/bin/sail up -d --wait',
    ])
        ->and(quietLines($processRunner))->toBe([
            'docker rm -f demo-app-laravel.test-1',
            'docker rm -f demo-app-pgsql-1',
            'docker rm -f demo-app-redis-1',
            'docker network rm demo-app_sail',
            'docker compose exec -T -u root laravel.test chown -R sail /home/sail',
        ]);
});

it('propagates the failure when the retry after cleanup also fails', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = withNothingInherited(new FakeProcessRunner);
    $processRunner->failOn('up -d --wait');

    makeSailStart($installerOption, $processRunner)->execute();
})->throws(CommandFailedException::class);

it('discards a project inherited from an earlier run of the same name', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;
    $processRunner->quietResult(needle: 'volume inspect demo-app_sail-pgsql', result: true);
    $processRunner->quietResult(needle: 'inspect', result: false);

    makeSailStart($installerOption, $processRunner)->execute();

    // Compose reuses volumes keyed by directory name, so a half-migrated database from a
    // previous scaffold would otherwise survive into this one.
    expect($processRunner->quietCommands[0]['command'])->toBe(['./vendor/bin/sail', 'down', '-v']);
});

it('leaves docker alone when nothing answers to the project name', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = withNothingInherited(new FakeProcessRunner);

    makeSailStart($installerOption, $processRunner)->execute();

    // The directory was created moments ago, so anything under its project name belongs to
    // something else — possibly a live application of the same name in another directory,
    // whose database `down -v` would take with it. Nothing found, nothing removed.
    expect(quietLines($processRunner))->not->toContain('./vendor/bin/sail down -v');
});

it('looks for both the containers and the volumes it would be inheriting', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = withNothingInherited(new FakeProcessRunner);

    makeSailStart($installerOption, $processRunner)->execute();

    $probeLines = array_map(
        fn (array $entry): string => implode(' ', $entry['command']),
        $processRunner->probeCommands,
    );

    expect($probeLines)->toBe([
        'docker container inspect demo-app-laravel.test-1',
        'docker container inspect demo-app-pgsql-1',
        'docker container inspect demo-app-redis-1',
        'docker volume inspect demo-app_sail-pgsql',
        'docker volume inspect demo-app_sail-redis',
    ]);
});

it('keeps the containers and volumes of the run it is resuming', function (): void {
    $installerOption = makeInstallerOption(['resume' => true]);
    $processRunner = new FakeProcessRunner;

    makeSailStart($installerOption, $processRunner)->execute();

    // Not even asked: a resume keeps what the run it is resuming left behind, so there is
    // no question whose answer could change what happens.
    expect(quietLines($processRunner))->not->toContain('./vendor/bin/sail down -v')
        ->and($processRunner->probeCommands)->toBe([]);
});
