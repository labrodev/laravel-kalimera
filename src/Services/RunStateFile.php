<?php

declare(strict_types=1);

namespace Kalimera\Services;

use JsonException;

/**
 * Everything a failed run leaves behind for `--continue`, in one file: the answers the
 * plan was built from, the steps that already ran, and the last healthy composer.json.
 *
 * They used to live apart — the answers in .kalimera.json, the steps in
 * .kalimera-steps.json, the manifest in the system temp directory — with a different
 * owner creating and deleting each. That let them disagree: a run interrupted between two
 * deletes, or an answers file lost on its own, resumed with fresh answers while the
 * checkpoint skipped steps that had run under the old ones, and the manifest outlived its
 * application to be restored into the next one scaffolded at the same path. One file,
 * written atomically and removed once, cannot be half there.
 *
 * Files written before the format was versioned are still read, so a run that failed on
 * an earlier release resumes on this one.
 */
readonly class RunStateFile
{
    public const string FILENAME = '.kalimera.json';

    /**
     * Where earlier releases kept the checkpoint. Read for a resume, removed with the rest.
     */
    private const string LEGACY_STEPS_FILENAME = '.kalimera-steps.json';

    private const int VERSION = 2;

    public function __construct(private string $targetPath) {}

    public function exists(): bool
    {
        return file_exists($this->path()) || file_exists($this->legacyStepsPath());
    }

    /**
     * Null when there are no usable answers — the file is missing, unreadable, or was
     * written before the answers were saved.
     *
     * @return array<string, mixed>|null
     */
    public function answers(): ?array
    {
        $answers = $this->read()['answers'] ?? null;

        if (! is_array($answers)) {
            return null;
        }

        /** @var array<string, mixed> $answers */
        return $answers;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function saveAnswers(array $answers): void
    {
        $this->write(['answers' => $answers] + $this->read());
    }

    /**
     * @return list<string>
     */
    public function completedSteps(): array
    {
        $steps = $this->read()['completedSteps'] ?? [];

        return is_array($steps) ? array_values(array_filter($steps, is_string(...))) : [];
    }

    public function recordStep(string $step): void
    {
        $state = $this->read();
        $steps = $this->completedSteps();

        if (in_array($step, $steps, true)) {
            return;
        }

        $state['completedSteps'] = [...$steps, $step];

        $this->write($state);
    }

    public function composerSnapshot(): ?string
    {
        $snapshot = $this->read()['composerSnapshot'] ?? null;

        return is_string($snapshot) && $snapshot !== '' ? $snapshot : null;
    }

    public function saveComposerSnapshot(string $contents): void
    {
        if ($this->composerSnapshot() === $contents) {
            return;
        }

        $this->write(['composerSnapshot' => $contents] + $this->read());
    }

    public function forget(): void
    {
        foreach ([$this->path(), $this->legacyStepsPath()] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * An unreadable file reads as empty. The caller that cares — a resume deciding whether
     * the answers can be trusted — asks exists() and answers() separately, so "there is a
     * file and it says nothing" stays distinguishable from "there is no file".
     *
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $decoded = $this->decode($this->path());

        if ($decoded === null) {
            return $this->legacySteps() === [] ? [] : ['completedSteps' => $this->legacySteps()];
        }

        if (($decoded['version'] ?? null) === self::VERSION) {
            unset($decoded['version']);

            return $decoded;
        }

        // Unversioned: the flat answers object earlier releases wrote, with the steps kept
        // in a file of their own.
        return ['answers' => $decoded, 'completedSteps' => $this->legacySteps()];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function write(array $state): void
    {
        if (! is_dir($this->targetPath)) {
            return;
        }

        (new FileWriter)(
            contents: json_encode(['version' => self::VERSION] + $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            path: $this->path(),
        );

        // The steps now live in the file above; a legacy copy left beside it would be read
        // again the next time this one is unreadable.
        if (file_exists($this->legacyStepsPath())) {
            unlink($this->legacyStepsPath());
        }
    }

    /**
     * @return list<string>
     */
    private function legacySteps(): array
    {
        $decoded = $this->decode($this->legacyStepsPath());

        if ($decoded === null || ! array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        try {
            $decoded = json_decode(associative: true, flags: JSON_THROW_ON_ERROR, json: (string) file_get_contents($path));
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function path(): string
    {
        return $this->targetPath.'/'.self::FILENAME;
    }

    private function legacyStepsPath(): string
    {
        return $this->targetPath.'/'.self::LEGACY_STEPS_FILENAME;
    }
}
