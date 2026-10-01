<?php

declare(strict_types=1);

use Kalimera\Exceptions\RequirementMissingException;
use Kalimera\Pipelines\PreflightCheck;
use Kalimera\Tests\Fakes\FakeExecutableFinder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

it('passes when every binary is present and docker answers', function (): void {
    $processRunner = new FakeProcessRunner;

    new PreflightCheck(
        executableFinder: new FakeExecutableFinder,
        processRunner: $processRunner,
    )->execute();

    $probes = array_column($processRunner->probeCommands, 'command');

    // PHP, then Composer, then the daemon.
    expect($probes)->toHaveCount(3)
        ->and(array_slice($probes[0], 0, 2))->toBe(['php', '-r'])
        ->and($probes[0][2])->toContain('PHP_VERSION')->toContain('"8.3.0"')
        ->and(array_slice($probes[1], 0, 2))->toBe(['php', '-r'])
        ->and($probes[1][2])->toContain('composer --version')->toContain('"2.2.0"')
        ->and($probes[2])->toBe(['docker', 'info']);
});

// `laravel new` installs a framework that needs PHP 8.3, and the host's package download
// passes --ignore-platform-req=ext-*, which composer only understands from 2.2.
it('names the host tool that is too old', function (string $needle, string $requirement): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->quietResult(needle: $needle, result: false);

    expect(fn () => new PreflightCheck(
        executableFinder: new FakeExecutableFinder,
        processRunner: $processRunner,
    )->execute())->toThrow(RequirementMissingException::class, $requirement);

    // It stops at the version, before asking the daemon anything.
    expect(array_column($processRunner->probeCommands, 'command'))->not->toContain(['docker', 'info']);
})->with([
    'php' => ['PHP_VERSION', 'PHP 8.3.0'],
    'composer' => ['composer --version', 'Composer 2.2.0'],
]);

it('checks the php version before the composer version', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->quietResult(needle: 'PHP_VERSION', result: false);

    expect(fn () => new PreflightCheck(
        executableFinder: new FakeExecutableFinder,
        processRunner: $processRunner,
    )->execute())->toThrow(RequirementMissingException::class, 'PHP 8.3.0');

    expect($processRunner->probeCommands)->toHaveCount(1);
});

// A probe only asks, so a rehearsal runs the version checks exactly as a real run does.
it('checks the host versions during a dry run too', function (): void {
    $processRunner = new FakeProcessRunner(dryRun: true);

    new PreflightCheck(
        executableFinder: new FakeExecutableFinder,
        processRunner: $processRunner,
    )->execute();

    $probes = array_column($processRunner->probeCommands, 'command');

    expect($probes)->toHaveCount(3)
        ->and($probes[0][2])->toContain('PHP_VERSION')
        ->and($probes[1][2])->toContain('composer --version')
        ->and($processRunner->quietCommands)->toBe([]);
});

it('names the missing binary rather than failing later', function (string $missing): void {
    expect(fn () => new PreflightCheck(
        executableFinder: new FakeExecutableFinder(missing: [$missing]),
        processRunner: new FakeProcessRunner,
    )->execute())->toThrow(RequirementMissingException::class, $missing);
})->with(['php', 'composer', 'laravel', 'docker', 'git']);

it('stops a real run when the docker daemon does not answer', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->quietResult(needle: 'docker info', result: false);

    expect(fn () => new PreflightCheck(
        executableFinder: new FakeExecutableFinder,
        processRunner: $processRunner,
    )->execute())->toThrow(RequirementMissingException::class, 'The Docker daemon');
});

it('lets a dry run continue when the docker daemon does not answer', function (): void {
    $processRunner = new FakeProcessRunner(dryRun: true);
    $processRunner->quietResult(needle: 'docker info', result: false);

    new PreflightCheck(
        executableFinder: new FakeExecutableFinder,
        processRunner: $processRunner,
    )->execute();

    // A rehearsal never reaches Docker, so a stopped daemon only warrants a warning —
    // which is why the probe has to run during a dry run instead of being skipped.
    expect(array_column($processRunner->probeCommands, 'command'))->toContain(['docker', 'info'])
        ->and(promptOutput())->toContain('Docker daemon is not running');
});

it('lets a dry run continue when a host tool is too old', function (string $needle, string $requirement): void {
    $processRunner = new FakeProcessRunner(dryRun: true);
    $processRunner->quietResult(needle: $needle, result: false);

    new PreflightCheck(
        executableFinder: new FakeExecutableFinder,
        processRunner: $processRunner,
    )->execute();

    // The rehearsal is how the plan gets seen on the machine about to be upgraded, so it
    // warns and carries on to the remaining checks instead of stopping.
    expect(promptOutput())->toContain($requirement.' or newer is required on the host')
        ->and(array_column($processRunner->probeCommands, 'command'))->toContain(['docker', 'info']);
})->with([
    'php' => ['PHP_VERSION', 'PHP 8.3.0'],
    'composer' => ['composer --version', 'Composer 2.2.0'],
]);
