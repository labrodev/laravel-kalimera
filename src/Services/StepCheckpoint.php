<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Contracts\Pipeline;
use ReflectionClass;

/**
 * Records which steps a run finished so `--continue` resumes where the previous one died
 * instead of replaying the whole plan. Replaying is mostly only expensive — a composer
 * require that is already satisfied is slow, not wrong — but a republished template
 * overwrites edits made while diagnosing the failure, which is exactly when a config file
 * gets touched by hand.
 *
 * Steps are keyed by class name rather than by label: rewording a label must not orphan
 * its checkpoint, and the class name is unique across the plan by construction.
 *
 * The record lives in the run state file beside the saved answers and is removed with
 * them once the scaffold completes. Deleting an entry by hand forces that step to run again.
 */
readonly class StepCheckpoint
{
    private RunStateFile $runStateFile;

    /**
     * A dry run rehearses the plan and changes nothing, so it must neither read a
     * checkpoint (it would skip steps whose commands the rehearsal is meant to print)
     * nor write one (the next real run would skip work that never happened).
     */
    public function __construct(string $targetPath, private bool $enabled = true)
    {
        $this->runStateFile = new RunStateFile($targetPath);
    }

    public function completed(Pipeline $pipeline): bool
    {
        return $this->enabled && in_array($this->key($pipeline), $this->runStateFile->completedSteps(), true);
    }

    public function record(Pipeline $pipeline): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->runStateFile->recordStep($this->key($pipeline));
    }

    /**
     * The plan finished: the checkpoint, the answers and the manifest snapshot go together,
     * so nothing is left behind that a later --continue could half-trust.
     */
    public function forget(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->runStateFile->forget();
    }

    private function key(Pipeline $pipeline): string
    {
        return new ReflectionClass($pipeline)->getShortName();
    }
}
