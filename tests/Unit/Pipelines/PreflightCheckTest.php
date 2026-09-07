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

    expect($processRunner->probeCommands[0]['command'])->toBe(['docker', 'info']);
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
    expect($processRunner->probeCommands)->toHaveCount(1);
});
