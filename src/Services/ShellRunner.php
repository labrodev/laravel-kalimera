<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Closure;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\CommandFailedException;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

use Symfony\Component\Process\Process;

readonly class ShellRunner implements ProcessRunner
{
    public function __construct(
        private bool $dryRun,
        private ?TranscriptLogger $transcriptLogger = null,
        private int $retryDelaySeconds = 3,
    ) {}

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * @param  list<string>  $command
     */
    public function runCommand(array $command, ?string $cwd = null, ?float $timeout = null, int $attempts = 1): void
    {
        // Transient container filesystem or network hiccups have failed otherwise-sound
        // composer commands mid-scaffold; a delayed retry absorbs them.
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->runCommandOnce(command: $command, cwd: $cwd, timeout: $timeout);

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
    private function runCommandOnce(array $command, ?string $cwd = null, ?float $timeout = null): void
    {
        $printable = $this->printable(command: $command, cwd: $cwd);

        info('→ '.$printable);
        $this->transcriptLogger?->command(cwd: $cwd, printable: $printable);

        if ($this->dryRun) {
            $this->transcriptLogger?->commandFinished(outcome: 'dry-run', printable: $printable);

            return;
        }

        $process = new Process(command: $command, cwd: $cwd);
        $process->setTimeout($timeout);

        // Deliberately no TTY passthrough: on macOS a TTY-mode wait() interrupted by a
        // signal misreports successful commands as failed, which made retries re-run
        // (and race) commands that had already succeeded. Streamed pipes give reliable
        // exit codes and a full transcript; compose's "TTY mode" warning is cosmetic.
        $process->run(callback: function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
            $this->transcriptLogger?->output($buffer);
        });

        if ($process->isRunning()) {
            $process->stop(timeout: 10);
        }

        if (! $process->isSuccessful()) {
            $this->transcriptLogger?->commandFinished(outcome: 'exit '.(string) $process->getExitCode(), printable: $printable);

            throw CommandFailedException::make(exitCode: (string) $process->getExitCode(), printable: $printable);
        }

        $this->transcriptLogger?->commandFinished(outcome: 'ok', printable: $printable);
    }

    /**
     * @param  list<string>  $command
     */
    public function runCommandQuietly(array $command, ?string $cwd = null): bool
    {
        $process = new Process(command: $command, cwd: $cwd);
        $process->setTimeout(30.0);
        $process->run();

        $success = $process->isSuccessful();
        $this->transcriptLogger?->quietCommand(printable: $this->printable(command: $command, cwd: $cwd), success: $success);

        return $success;
    }

    public function applyFileChange(string $description, Closure $action): void
    {
        $this->transcriptLogger?->fileAction(description: $description, dryRun: $this->dryRun);

        if ($this->dryRun) {
            info('· would '.$description);

            return;
        }

        $action();

        // Settle before the next command: a container reading a host-renamed file too
        // quickly can see it empty through the macOS VirtioFS mount.
        sleep(1);

        info('· '.$description);
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
