<?php

declare(strict_types=1);

namespace Kalimera\Services;

use JsonException;
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
 * The file sits beside .kalimera.json inside the application and is removed once the
 * scaffold completes. Deleting an entry by hand forces that step to run again.
 */
readonly class StepCheckpoint
{
    private const string FILENAME = '.kalimera-steps.json';

    /**
     * A dry run rehearses the plan and changes nothing, so it must neither read a
     * checkpoint (it would skip steps whose commands the rehearsal is meant to print)
     * nor write one (the next real run would skip work that never happened).
     */
    public function __construct(private string $targetPath, private bool $enabled = true) {}

    public function completed(Pipeline $pipeline): bool
    {
        return in_array($this->key($pipeline), $this->recorded(), true);
    }

    public function record(Pipeline $pipeline): void
    {
        if (! $this->enabled || ! is_dir($this->targetPath)) {
            return;
        }

        $recorded = $this->recorded();
        $key = $this->key($pipeline);

        if (in_array($key, $recorded, true)) {
            return;
        }

        $recorded[] = $key;

        (new FileWriter)(
            contents: json_encode($recorded, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            path: $this->path(),
        );
    }

    public function forget(): void
    {
        if (! $this->enabled) {
            return;
        }

        if (file_exists($this->path())) {
            unlink($this->path());
        }
    }

    /**
     * @return list<string>
     */
    private function recorded(): array
    {
        if (! $this->enabled || ! file_exists($this->path())) {
            return [];
        }

        try {
            $decoded = json_decode(associative: true, flags: JSON_THROW_ON_ERROR, json: (string) file_get_contents($this->path()));
        } catch (JsonException) {
            // A half-written checkpoint means the previous run was killed mid-write.
            // Reading it as empty replays steps that already ran, which is the safe
            // direction to be wrong in.
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }

    private function key(Pipeline $pipeline): string
    {
        return new ReflectionClass($pipeline)->getShortName();
    }

    private function path(): string
    {
        return $this->targetPath.'/'.self::FILENAME;
    }
}
