<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Exceptions\PortInUseException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\SailStart;
use Kalimera\Services\ComposeProjectName;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakePortChecker;
use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * @param  list<int>  $busyPorts
 */
function makeSailStart(InstallerOption $installerOption, FakeProcessRunner $processRunner, array $busyPorts = []): SailStart
{
    return new SailStart(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
        portChecker: new FakePortChecker($busyPorts),
    );
}

/**
 * The project name a fresh run pins: the directory name plus a hash of the full path.
 */
function projectOf(InstallerOption $installerOption): string
{
    return new ComposeProjectName($installerOption->targetPath)->unique();
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
        ->and($processRunner->quietCommands)->toBe([]);
});

// Both halves matter. That nothing was performed is the promise; that the removal was still
// asked for is what makes the first half evidence rather than an accident of the fake —
// an empty performed-list would also be produced by a step that never reached the call.
it('asks for the leftover removal but performs nothing during a dry run', function (): void {
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
        ]);
});

it('removes leftover containers and retries when the first start fails', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = withNothingInherited(new FakeProcessRunner);
    $processRunner->failOn('up -d --wait', times: 1);

    makeSailStart($installerOption, $processRunner)->execute();

    $project = projectOf($installerOption);

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail up -d --wait',
        './vendor/bin/sail up -d --wait',
    ])
        ->and(quietLines($processRunner))->toBe([
            'docker rm -f '.$project.'-laravel.test-1',
            'docker rm -f '.$project.'-pgsql-1',
            'docker rm -f '.$project.'-redis-1',
            'docker network rm '.$project.'_sail',
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
    $processRunner->quietResult(needle: 'volume inspect '.projectOf($installerOption).'_sail-pgsql', result: true);
    $processRunner->quietResult(needle: 'inspect', result: false);

    makeSailStart($installerOption, $processRunner)->execute();

    // The name is unique to this path, so what answers to it was left by an earlier run in
    // this very directory — and its half-migrated database would otherwise survive into
    // this one.
    expect($processRunner->quietCommands[0]['command'])->toBe(['./vendor/bin/sail', 'down', '-v']);
});

it('leaves docker alone when nothing answers to the project name', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = withNothingInherited(new FakeProcessRunner);

    makeSailStart($installerOption, $processRunner)->execute();

    // Nothing found, nothing removed: a blind `down -v` would buy nothing for its risk.
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

    $project = projectOf($installerOption);

    expect($probeLines)->toBe([
        'docker container inspect '.$project.'-laravel.test-1',
        'docker container inspect '.$project.'-pgsql-1',
        'docker container inspect '.$project.'-redis-1',
        'docker volume inspect '.$project.'_sail-pgsql',
        'docker volume inspect '.$project.'_sail-redis',
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

/**
 * An application directory with the .env sail:install leaves behind.
 *
 * @param  array<string, mixed>  $overrides
 */
function installerOptionWithEnv(string $env, array $overrides = []): InstallerOption
{
    $installerOption = makeInstallerOption($overrides);
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/.env', $env);

    return $installerOption;
}

// ~/work/api and ~/side/api were one compose project, so scaffolding the second cleared
// the first one's database.
it('gives two applications of the same name different compose projects', function (): void {
    $first = makeInstallerOption(['targetPath' => tempDir().'/api']);
    $second = makeInstallerOption(['targetPath' => tempDir().'/api']);

    expect(projectOf($first))->toStartWith('api-')
        ->and(projectOf($first))->not->toBe(projectOf($second));
});

it('pins the compose project name in .env before asking docker about it', function (): void {
    $installerOption = installerOptionWithEnv("APP_NAME=Laravel\n");
    $processRunner = withNothingInherited(new FakeProcessRunner);

    makeSailStart($installerOption, $processRunner)->execute();

    expect((string) file_get_contents($installerOption->targetPath.'/.env'))
        ->toContain('COMPOSE_PROJECT_NAME='.projectOf($installerOption))
        ->and($processRunner->probeCommands[0]['command'])->toBe(['docker', 'container', 'inspect', projectOf($installerOption).'-laravel.test-1']);
});

it('keeps a project name .env already pins', function (): void {
    $installerOption = installerOptionWithEnv("COMPOSE_PROJECT_NAME=chosen-by-hand\n");
    $processRunner = withNothingInherited(new FakeProcessRunner);

    makeSailStart($installerOption, $processRunner)->execute();

    expect((string) file_get_contents($installerOption->targetPath.'/.env'))->toBe("COMPOSE_PROJECT_NAME=chosen-by-hand\n")
        ->and($processRunner->probeCommands[0]['command'])->toBe(['docker', 'container', 'inspect', 'chosen-by-hand-laravel.test-1']);
});

// Renaming mid-scaffold would orphan the running containers while they still hold the
// ports the renamed ones need.
it('keeps the directory-name project of a resumed run that predates the pinned name', function (): void {
    $installerOption = installerOptionWithEnv("APP_NAME=Laravel\n", ['resume' => true]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('up -d --wait', times: 1);

    makeSailStart($installerOption, $processRunner)->execute();

    expect((string) file_get_contents($installerOption->targetPath.'/.env'))->not->toContain('COMPOSE_PROJECT_NAME')
        ->and(quietLines($processRunner))->toContain('docker rm -f demo-app-laravel.test-1');
});

it('names the port another program holds instead of retrying into it', function (): void {
    $installerOption = installerOptionWithEnv("APP_PORT=8080\n");
    $processRunner = withNothingInherited(new FakeProcessRunner);
    $processRunner->failOn('up -d --wait');

    $caught = null;

    try {
        makeSailStart($installerOption, $processRunner, busyPorts: [8080, 5432])->execute();
    } catch (PortInUseException $portInUseException) {
        $caught = $portInUseException;
    }

    expect($caught)->toBeInstanceOf(PortInUseException::class)
        ->and($caught?->getMessage())->toContain('8080 (APP_PORT)')
        ->and($caught?->getMessage())->toContain('5432 (FORWARD_DB_PORT)')
        ->and($caught?->getPrevious())->toBeInstanceOf(CommandFailedException::class)
        // No third `up` into a port that is still taken.
        ->and($processRunner->commandLines())->toBe(['./vendor/bin/sail up -d --wait']);
});

it('checks the ports only after clearing its own leftovers, which may be what holds them', function (): void {
    $installerOption = installerOptionWithEnv("APP_NAME=Laravel\n");
    $processRunner = withNothingInherited(new FakeProcessRunner);
    $processRunner->failOn('up -d --wait', times: 1);

    makeSailStart($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail up -d --wait',
        './vendor/bin/sail up -d --wait',
    ]);
});
