<?php

declare(strict_types=1);

namespace Kalimera\Tests\Fakes;

use Closure;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\CommandFailedException;

class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: string|null, replayTail: bool}> */
    public array $commands = [];

    /** @var list<string> */
    public array $fileActions = [];

    /**
     * Quiet side effects that were actually performed. Probes are kept apart in
     * $probeCommands: one changes the environment and the other only asks about it, so a
     * test asserting "nothing was touched" must not have to filter questions out first.
     *
     * @var list<array{command: list<string>, cwd: string|null}>
     */
    public array $quietCommands = [];

    /** @var list<array{command: list<string>, cwd: string|null}> */
    public array $probeCommands = [];

    /**
     * Quiet side effects a dry run declined to perform. Without this the promise "a
     * rehearsal touches nothing" is true by construction — the fake would simply forget
     * the call, and an assertion on an empty $quietCommands could not fail whatever the
     * caller did. Recording the attempt separately lets a test show both halves: the step
     * did ask for the destructive command, and it did not happen.
     *
     * @var list<array{command: list<string>, cwd: string|null}>
     */
    public array $skippedQuietCommands = [];

    /** @var array<string, array{times: int|null, output: string}> */
    private array $failures = [];

    /** @var array<string, bool> */
    private array $quietResults = [];

    /** @var array<string, Closure> */
    private array $sideEffects = [];

    /** @var list<array{command: list<string>, cwd: string|null}> */
    public array $askCommands = [];

    /** @var array<string, string|null> */
    private array $answers = [];

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
     * always when $times is null, otherwise only the next $times matches. The output
     * stands in for what the real command would have printed before it failed.
     */
    public function failOn(string $needle, ?int $times = null, string $output = ''): void
    {
        $this->failures[$needle] = ['times' => $times, 'output' => $output];
    }

    public function quietResult(string $needle, bool $result): void
    {
        $this->quietResults[$needle] = $result;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function runCommand(array $command, ?string $cwd = null, ?float $timeout = null, int $attempts = 1, bool $replayTail = true): void
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->runCommandOnce(command: $command, cwd: $cwd, replayTail: $replayTail);

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
    private function runCommandOnce(array $command, ?string $cwd = null, bool $replayTail = true): void
    {
        $this->commands[] = ['command' => $command, 'cwd' => $cwd, 'replayTail' => $replayTail];

        $printable = implode(' ', $command);

        foreach ($this->sideEffects as $needle => $action) {
            if (str_contains($printable, $needle)) {
                $action();
            }
        }

        foreach ($this->failures as $needle => $failure) {
            if (! str_contains($printable, $needle)) {
                continue;
            }

            if ($failure['times'] === null) {
                throw CommandFailedException::make(exitCode: '1', output: $failure['output'], printable: $printable);
            }

            if ($failure['times'] > 0) {
                $this->failures[$needle]['times'] = $failure['times'] - 1;

                throw CommandFailedException::make(exitCode: '1', output: $failure['output'], printable: $printable);
            }
        }
    }

    public function probe(array $command, ?string $cwd = null): bool
    {
        $this->probeCommands[] = ['command' => $command, 'cwd' => $cwd];

        return $this->answerFor($command);
    }

    /**
     * @param  list<string>  $command
     */
    public function ask(array $command, ?string $cwd = null): ?string
    {
        $this->askCommands[] = ['command' => $command, 'cwd' => $cwd];

        $joined = implode(' ', $command);

        foreach ($this->answers as $needle => $answer) {
            if (str_contains($joined, $needle)) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * What ask() returns for a command containing the needle; anything unanswered gets
     * null, as a command that failed would.
     */
    public function answer(string $needle, ?string $output): void
    {
        $this->answers[$needle] = $output;
    }

    public function attemptQuietly(array $command, ?string $cwd = null): bool
    {
        if ($this->dryRun) {
            $this->skippedQuietCommands[] = ['command' => $command, 'cwd' => $cwd];

            return false;
        }

        $this->quietCommands[] = ['command' => $command, 'cwd' => $cwd];

        return $this->answerFor($command);
    }

    /**
     * @param  list<string>  $command
     */
    private function answerFor(array $command): bool
    {
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
