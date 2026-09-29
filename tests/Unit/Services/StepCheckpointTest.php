<?php

declare(strict_types=1);

use Kalimera\Contracts\Pipeline;
use Kalimera\Pipelines\SailStart;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Services\StepCheckpoint;
use Kalimera\Tests\Fakes\FakePortChecker;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function fakeStep(string $label = 'a step'): Pipeline
{
    return new readonly class($label) implements Pipeline
    {
        public function __construct(private string $label) {}

        public function label(): string
        {
            return $this->label;
        }

        public function execute(): void {}
    };
}

/**
 * @return list<string>
 */
function recordedSteps(string $targetPath): array
{
    return json_decode((string) file_get_contents($targetPath.'/.kalimera.json'), true)['completedSteps'];
}

it('reports a step as completed only after it was recorded', function (): void {
    $targetPath = tempDir();
    $stepCheckpoint = new StepCheckpoint($targetPath);
    $step = fakeStep();

    expect($stepCheckpoint->completed($step))->toBeFalse();

    $stepCheckpoint->record($step);

    expect($stepCheckpoint->completed($step))->toBeTrue()
        ->and(file_exists($targetPath.'/.kalimera.json'))->toBeTrue();
});

it('keys steps by class so a reworded label keeps its checkpoint', function (): void {
    $stepCheckpoint = new StepCheckpoint(tempDir());

    $stepCheckpoint->record(fakeStep('Building and starting the Sail containers'));

    expect($stepCheckpoint->completed(fakeStep('Starting the containers')))->toBeTrue();
});

it('distinguishes one pipeline class from another', function (): void {
    $targetPath = tempDir();
    $stepCheckpoint = new StepCheckpoint($targetPath);

    $stepCheckpoint->record(fakeStep());

    $sailStart = new SailStart(
        installerOption: makeInstallerOption(['targetPath' => $targetPath]),
        processRunner: new FakeProcessRunner,
        sailCommandBuilder: new SailCommandBuilder($targetPath),
        portChecker: new FakePortChecker,
    );

    expect($stepCheckpoint->completed($sailStart))->toBeFalse();
});

it('records a step once however often it is repeated', function (): void {
    $targetPath = tempDir();
    $stepCheckpoint = new StepCheckpoint($targetPath);
    $step = fakeStep();

    $stepCheckpoint->record($step);
    $stepCheckpoint->record($step);

    expect(recordedSteps($targetPath))->toHaveCount(1);
});

it('writes nothing and reads nothing when disabled for a dry run', function (): void {
    $targetPath = tempDir();
    $step = fakeStep();

    new StepCheckpoint($targetPath)->record($step);

    $disabled = new StepCheckpoint(targetPath: $targetPath, enabled: false);

    expect($disabled->completed($step))->toBeFalse();

    $disabled->record(fakeStep('another'));

    expect(recordedSteps($targetPath))->toHaveCount(1);
});

it('leaves the file alone when forgetting during a dry run', function (): void {
    $targetPath = tempDir();

    new StepCheckpoint($targetPath)->record(fakeStep());
    new StepCheckpoint(targetPath: $targetPath, enabled: false)->forget();

    expect(file_exists($targetPath.'/.kalimera.json'))->toBeTrue();
});

it('removes the file when the run completes', function (): void {
    $targetPath = tempDir();
    $stepCheckpoint = new StepCheckpoint($targetPath);

    $stepCheckpoint->record(fakeStep());
    $stepCheckpoint->forget();

    expect(file_exists($targetPath.'/.kalimera.json'))->toBeFalse();
});

it('skips recording when the application directory does not exist yet', function (): void {
    $targetPath = tempDir().'/never-created';

    new StepCheckpoint($targetPath)->record(fakeStep());

    expect(is_dir($targetPath))->toBeFalse();
});

it('replays every step when the checkpoint was left half written', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera.json', '{"version": 2, "completedSteps": ["AppCrea');

    expect(new StepCheckpoint($targetPath)->completed(fakeStep()))->toBeFalse();
});

it('ignores entries that are not strings', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera.json', '{"version": 2, "completedSteps": [42, {"a": "b"}]}');

    expect(new StepCheckpoint($targetPath)->completed(fakeStep()))->toBeFalse();
});

// A run that failed on an earlier release kept its checkpoint in a file of its own, and
// upgrading between the failure and the --continue must not replay what already ran.
it('reads the checkpoint an earlier release left in its own file', function (): void {
    $targetPath = tempDir();
    $step = fakeStep();
    file_put_contents($targetPath.'/.kalimera-steps.json', json_encode([new ReflectionClass($step)->getShortName()]));

    expect(new StepCheckpoint($targetPath)->completed($step))->toBeTrue();
});

it('folds the legacy checkpoint into the state file on the next record', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera-steps.json', '["AppCreate"]');

    new StepCheckpoint($targetPath)->record(fakeStep());

    expect(file_exists($targetPath.'/.kalimera-steps.json'))->toBeFalse()
        ->and(recordedSteps($targetPath))->toHaveCount(2)
        ->and(recordedSteps($targetPath)[0])->toBe('AppCreate');
});

it('removes the legacy checkpoint with the rest of the state', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera-steps.json', '["AppCreate"]');

    new StepCheckpoint($targetPath)->forget();

    expect(file_exists($targetPath.'/.kalimera-steps.json'))->toBeFalse();
});
