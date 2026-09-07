<?php

declare(strict_types=1);

namespace Kalimera\Contracts;

use Closure;

interface ProcessRunner
{
    /**
     * What to pass as `attempts` for a command that reaches the network. Composer and npm
     * downloads arrive truncated often enough over a slow link that a single failure says
     * nothing about whether the command was sound.
     */
    public const int NETWORK_ATTEMPTS = 3;

    public function isDryRun(): bool;

    /**
     * @param  list<string>  $command
     */
    public function runCommand(array $command, ?string $cwd = null, ?float $timeout = null, int $attempts = 1): void;

    /**
     * Ask the environment a question — is Docker answering, does this file parse. Runs
     * during a dry run too: it changes nothing, and a rehearsal that skipped the question
     * could not report the answer.
     *
     * Never throws: a command that hangs past its cap or cannot start counts as "no".
     *
     * @param  list<string>  $command
     */
    public function probe(array $command, ?string $cwd = null): bool;

    /**
     * Best-effort housekeeping whose failure is not worth stopping the run for — removing
     * a leftover container, fixing ownership. Unlike a probe it changes the environment,
     * so a dry run skips it entirely and gets false back.
     *
     * Never throws, for the same reason it returns a bool: every caller is entitled to
     * ignore the answer and carry on.
     *
     * @param  list<string>  $command
     */
    public function attemptQuietly(array $command, ?string $cwd = null): bool;

    public function applyFileChange(string $description, Closure $action): void;
}
