<?php

declare(strict_types=1);

namespace Kalimera\Contracts;

use Closure;

interface ProcessRunner
{
    public function isDryRun(): bool;

    /**
     * @param  list<string>  $command
     */
    public function runCommand(array $command, ?string $cwd = null, ?float $timeout = null, int $attempts = 1): void;

    /**
     * @param  list<string>  $command
     */
    public function runCommandQuietly(array $command, ?string $cwd = null): bool;

    public function applyFileChange(string $description, Closure $action): void;
}
