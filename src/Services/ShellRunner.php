<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Closure;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\CommandFailedException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

readonly class ShellRunner implements ProcessRunner
{
    /**
     * How often the run loop wakes to advance the progress line. Short enough that the
     * spinner reads as live, long enough that a fifteen-minute build costs nothing.
     */
    private const int POLL_MICROSECONDS = 100_000;

    public function __construct(
        private bool $dryRun,
        private ?TranscriptLogger $transcriptLogger = null,
        private int $retryDelaySeconds = 3,
        private CommandOutputPrinter $commandOutputPrinter = new CommandOutputPrinter,
        /**
         * Housekeeping and probes are meant to be quick. The cap is what stops a wedged
         * Docker daemon from stalling the scaffold — passing it is treated as failure,
         * not as an error worth stopping for.
         */
        private float $quietTimeoutSeconds = 30.0,
    ) {}

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * @param  list<string>  $command
     */
    public function runCommand(array $command, ?string $cwd = null, ?float $timeout = null, int $attempts = 1, bool $replayTail = true): void
    {
        // For callers that pass NETWORK_ATTEMPTS: a download can fail transiently, and a
        // delayed retry is cheap next to restarting the scaffold.
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                // Only the last attempt replays the tail. An earlier one has the retry
                // notice below to explain itself, and forty lines per attempt would bury
                // the failure that finally sticks under two copies of the ones that did not.
                $this->runCommandOnce(
                    command: $command,
                    cwd: $cwd,
                    replayTail: $replayTail && $attempt === $attempts,
                    timeout: $timeout,
                );

                return;
            } catch (CommandFailedException $commandFailedException) {
                if ($attempt === $attempts) {
                    throw $commandFailedException;
                }

                warning(sprintf('Command failed — retrying in %d seconds.', $this->retryDelaySeconds));
                sleep($this->retryDelaySeconds);
            }
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommandOnce(array $command, ?string $cwd = null, ?float $timeout = null, bool $replayTail = true): void
    {
        $printable = $this->printable(command: $command, cwd: $cwd);

        info('→ '.$printable);
        $this->transcriptLogger?->command(cwd: $cwd, printable: $printable);

        if ($this->dryRun) {
            $this->transcriptLogger?->commandFinished(outcome: 'dry-run', printable: $printable);

            return;
        }

        $process = new Process(command: $this->spawnable(command: $command, cwd: $cwd), cwd: $cwd);
        $process->setTimeout($timeout);

        $this->commandOutputPrinter->begin();

        $tail = '';
        $aborted = null;

        try {
            // Deliberately no TTY passthrough: streamed pipes give a full transcript and an
            // exit code to read, and the "TTY mode" warning compose prints is cosmetic.
            //
            // Polling rather than run(): isRunning() drains the pipes and fires the
            // callback, so the loop keeps the progress line moving through the long
            // silences of an image build. wait() must take no argument — passing the
            // callback again would replace the one start() registered.
            $process->start(callback: function (string $type, string $buffer): void {
                // The terminal gets a progress line, not the raw stream — a Sail build
                // alone is tens of thousands of lines. The transcript keeps all of it.
                $this->commandOutputPrinter->write(buffer: $buffer, error: $type === Process::ERR);
                $this->transcriptLogger?->output($buffer);
            });

            while ($process->isRunning()) {
                // isRunning() never checks it for us, and skipping this would quietly
                // strip any timeout a caller asked for.
                $process->checkTimeout();
                $this->commandOutputPrinter->tick();
                usleep(self::POLL_MICROSECONDS);
            }

            $process->wait();
        } catch (ProcessRuntimeException $processException) {
            // Held rather than rethrown so the tail collected below still reaches both the
            // terminal and the caller — a command killed at its timeout has usually said
            // why it was slow, and that is the whole diagnosis.
            //
            // The base class rather than the two specific ones: a process that never
            // launched at all (a missing working directory) fails just as much as one that
            // timed out, and letting that escape as a raw Symfony exception would slip past
            // the retry budget and every `catch (CommandFailedException)` recovery path —
            // the exact gap this conversion exists to close. It also keeps working at the
            // lower bound of the symfony/process constraint, where the dedicated
            // start-failure class does not exist yet.
            $aborted = $processException;
        } finally {
            if ($process->isRunning()) {
                $process->stop(timeout: 10);
            }

            // In a finally so a throw cannot leave the status line painted for the next
            // write to land on. isSuccessful() is safe here: it reads a null exit code
            // as failure rather than throwing.
            $tail = $this->commandOutputPrinter->finish($process->isSuccessful(), replay: $replayTail);
        }

        if ($aborted !== null) {
            $reason = $this->abortReason($aborted);

            $this->transcriptLogger?->commandFinished(outcome: $reason, printable: $printable);

            throw CommandFailedException::aborted(output: $tail, previous: $aborted, printable: $printable, reason: $reason);
        }

        if (! $process->isSuccessful()) {
            $this->transcriptLogger?->commandFinished(outcome: 'exit '.(string) $process->getExitCode(), printable: $printable);

            throw CommandFailedException::make(
                exitCode: (string) $process->getExitCode(),
                output: $tail,
                printable: $printable,
            );
        }

        $this->transcriptLogger?->commandFinished(outcome: 'ok', printable: $printable);
    }

    private function abortReason(ProcessRuntimeException $throwable): string
    {
        return match (true) {
            // Cast rather than %d: the cap is a float, and a sub-second one — which is only
            // ever a test's — would otherwise report itself as having timed out after 0s.
            $throwable instanceof ProcessTimedOutException => sprintf('timed out after %s seconds', (string) $throwable->getExceededTimeout()),
            $throwable instanceof ProcessSignaledException => sprintf('killed by signal %d', $throwable->getSignal()),
            default => $throwable->getMessage(),
        };
    }

    /**
     * @param  list<string>  $command
     */
    public function probe(array $command, ?string $cwd = null): bool
    {
        return $this->quietly(command: $command, cwd: $cwd, printable: $this->printable(command: $command, cwd: $cwd))?->isSuccessful() ?? false;
    }

    /**
     * @param  list<string>  $command
     */
    public function ask(array $command, ?string $cwd = null): ?string
    {
        $process = $this->quietly(command: $command, cwd: $cwd, printable: $this->printable(command: $command, cwd: $cwd));

        if ($process === null || ! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    /**
     * @param  list<string>  $command
     */
    public function attemptQuietly(array $command, ?string $cwd = null): bool
    {
        $printable = $this->printable(command: $command, cwd: $cwd);

        // "Nothing is executed" has to mean nothing: these commands remove containers and
        // volumes, which a rehearsal must never do behind the user's back.
        //
        // Printed all the same. `--dry-run` promises every command, and the quiet ones are
        // the only destructive step in the plan — the one a reader most needs to see coming.
        if ($this->dryRun) {
            info('→ would '.$printable);
            $this->transcriptLogger?->quietSkipped($printable);

            return false;
        }

        return $this->quietly(command: $command, cwd: $cwd, printable: $printable)?->isSuccessful() ?? false;
    }

    /**
     * @param  list<string>  $command
     */
    private function quietly(array $command, string $printable, ?string $cwd): ?Process
    {
        $process = new Process(command: $this->spawnable(command: $command, cwd: $cwd), cwd: $cwd);
        $process->setTimeout($this->quietTimeoutSeconds);

        try {
            $process->run();
        } catch (Throwable $throwable) {
            // A quiet command promises the run carries on regardless of the outcome, so
            // a timeout or a binary that will not launch must not be louder than a
            // non-zero exit — both mean "it did not work, keep going".
            $this->transcriptLogger?->quietAborted(printable: $printable, reason: $throwable->getMessage());

            return null;
        }

        $this->transcriptLogger?->quietCommand(printable: $printable, success: $process->isSuccessful());

        return $process;
    }

    public function applyFileChange(string $description, Closure $action): void
    {
        $this->transcriptLogger?->fileAction(description: $description, dryRun: $this->dryRun);

        if ($this->dryRun) {
            info('· would '.$description);

            return;
        }

        $action();

        info('· '.$description);
    }

    /**
     * A relative program path is resolved against the working directory before it reaches
     * proc_open, because on macOS it runs twice otherwise. proc_open hands an array command
     * to posix_spawn, which resolves `./vendor/bin/sail` against the *parent's* directory,
     * reports ENOENT — and the child, spawned into the right directory, executes it anyway.
     * Symfony Process reads the reported failure as "not started" and falls back to
     * `exec ./vendor/bin/sail …` through a shell, which runs it a second time, concurrently.
     *
     * Measured: 10 `sail php` calls through Process executed 20 times with the relative
     * path and 10 times with the absolute one; plain proc_open returned false for a command
     * that demonstrably ran. Every sail command the scaffold issued ran as two racing
     * copies — two composers rewriting composer.json, two `migrate`s creating the same
     * table, two publishes creating the same directory.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function spawnable(array $command, ?string $cwd): array
    {
        $program = $command[0] ?? '';

        if ($cwd === null || ! str_contains($program, '/') || str_starts_with($program, '/')) {
            return $command;
        }

        $command[0] = rtrim($cwd, '/').'/'.(str_starts_with($program, './') ? substr($program, 2) : $program);

        return $command;
    }

    /**
     * @param  list<string>  $command
     */
    private function printable(array $command, ?string $cwd): string
    {
        $prefix = $cwd === null ? '' : sprintf('[%s] ', basename($cwd));

        return $prefix.implode(' ', $command);
    }
}
