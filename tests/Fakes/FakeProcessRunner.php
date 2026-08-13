<?php

declare(strict_types=1);

namespace Kalimera\Tests\Fakes;

use Closure;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\CommandFailedException;

class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: string|null}> */
    public array $commands = [];

    /** @var list<string> */
    public array $fileActions = [];

    /** @var list<array{command: list<string>, cwd: string|null}> */
    public array $quietCommands = [];

    /** @var array<string, int|null> */
    private array $failures = [];

    /** @var array<string, bool> */
    private array $quietResults = [];

    /** @var array<string, Closure> */
    private array $sideEffects = [];

    public function __construct(private readonly bool $dryRun = false) {}

    /**
     * Run a side effect whenever a matching command executes, so a fake command can
     * mutate the filesystem the way the real one would.
     */
    public function onCommand(string $needle, Closure $action): void
    {
        $this->sideEffects[$needle] = $action;
    }

    /**
     * Make every runCommand whose joined command contains the needle fail —
     * always when $times is null, otherwise only the next $times matches.
     */
    public function failOn(string $needle, ?int $times = null): void
    {
        $this->failures[$needle] = $times;
    }

    public function quietResult(string $needle, bool $result): void
    {
        $this->quietResults[$needle] = $result;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function runCommand(array $command, ?string $cwd = null, ?float $timeout = null, int $attempts = 1): void
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->runCommandOnce(command: $command, cwd: $cwd);

                return;
            } catch (CommandFailedException $commandFailedException) {
                if ($attempt === $attempts) {
                    throw $commandFailedException;
                }
            }
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommandOnce(array $command, ?string $cwd = null): void
    {
        $this->commands[] = ['command' => $command, 'cwd' => $cwd];

        $printable = implode(' ', $command);

        foreach ($this->sideEffects as $needle => $action) {
            if (str_contains($printable, $needle)) {
                $action();
            }
        }

        foreach ($this->failures as $needle => $remaining) {
            if (! str_contains($printable, $needle)) {
                continue;
            }

            if ($remaining === null) {
                throw CommandFailedException::make(exitCode: '1', printable: $printable);
            }

            if ($remaining > 0) {
                $this->failures[$needle] = $remaining - 1;

                throw CommandFailedException::make(exitCode: '1', printable: $printable);
            }
        }
    }

    public function runCommandQuietly(array $command, ?string $cwd = null): bool
    {
        $this->quietCommands[] = ['command' => $command, 'cwd' => $cwd];

        $printable = implode(' ', $command);

        foreach ($this->quietResults as $needle => $result) {
            if (str_contains($printable, $needle)) {
                return $result;
            }
        }

        return true;
    }

    public function applyFileChange(string $description, Closure $action): void
    {
        $this->fileActions[] = $description;

        if ($this->dryRun) {
            return;
        }

        $action();
    }

    /**
     * @return list<string>
     */
    public function commandLines(): array
    {
        return array_map(
            fn (array $entry): string => implode(' ', $entry['command']),
            $this->commands,
        );
    }
}
