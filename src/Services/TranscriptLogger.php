<?php

declare(strict_types=1);

namespace Kalimera\Services;

class TranscriptLogger
{
    /**
     * Lines recorded before the destination existed. The default transcript lives inside
     * the application directory, which `laravel new` only creates a few steps in.
     */
    private string $pending = '';

    /**
     * Held open for the whole run. Child output arrives in thousands of small chunks — a
     * Sail image build alone is tens of thousands — and reopening, locking and closing
     * the file for each one cost more than the write itself. The lock is gone with it:
     * the run lock already keeps a second kalimera off this application, and with it off
     * this transcript.
     *
     * @var resource|null
     */
    private $handle;

    public function __construct(private ?string $path = null) {}

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Point the transcript at a file. Everything buffered so far is written as soon as
     * the parent directory exists.
     */
    public function useFile(string $path): void
    {
        $this->close();
        $this->path = $path;

        $this->flush();
    }

    public function hasPendingLines(): bool
    {
        return $this->pending !== '';
    }

    /**
     * @param  list<string>  $arguments
     */
    public function begin(array $arguments): void
    {
        $this->write('Kalimera transcript');
        $this->write('argv: '.implode(' ', $arguments));
        $this->write('Child process output is condensed on the terminal and captured in full below.');
    }

    public function step(int $index, string $label, int $total): void
    {
        $this->write(sprintf('Step %d/%d — %s', $index, $total, $label));
    }

    /**
     * A step the checkpoint reports as finished by an earlier run. The transcript of a
     * --continue would otherwise show a gap where the work used to be.
     */
    public function stepSkipped(int $index, string $label, int $total): void
    {
        $this->write(sprintf('Step %d/%d — %s [skipped: completed by an earlier run]', $index, $total, $label));
    }

    public function command(?string $cwd, string $printable): void
    {
        $this->write(($cwd === null ? 'run: ' : 'run in '.$cwd.': ').$printable);
    }

    public function commandFinished(string $outcome, string $printable): void
    {
        $this->write(sprintf('finished [%s]: %s', $outcome, $printable));
    }

    public function quietCommand(string $printable, bool $success): void
    {
        $this->write(sprintf('quiet [%s]: %s', $success ? 'ok' : 'failed', $printable));
    }

    public function quietSkipped(string $printable): void
    {
        $this->write('quiet [dry-run]: '.$printable);
    }

    /**
     * A quiet command that never got to report an exit code — it timed out, or could not
     * be launched. The run continues, so the reason only survives here.
     */
    public function quietAborted(string $printable, string $reason): void
    {
        $this->write(sprintf('quiet [aborted]: %s — %s', $printable, $reason));
    }

    public function fileAction(string $description, bool $dryRun): void
    {
        $this->write('file: '.($dryRun ? 'would ' : '').$description);
    }

    public function output(string $buffer): void
    {
        $this->append($buffer);
    }

    public function outcome(string $message): void
    {
        $this->write('outcome: '.$message);
    }

    private function write(string $line): void
    {
        $this->append('['.date('Y-m-d H:i:s').'] '.$line.PHP_EOL);
    }

    private function append(string $text): void
    {
        $this->pending .= $text;

        $this->flush();
    }

    private function flush(): void
    {
        if ($this->pending === '') {
            return;
        }

        $handle = $this->handle ?? $this->open();

        if ($handle === null) {
            return;
        }

        // Unbuffered on purpose: a run that is killed — which is when the transcript gets
        // read — must not take its last lines down with it.
        if (fwrite($handle, $this->pending) !== false) {
            $this->pending = '';
        }
    }

    /**
     * @return resource|null
     */
    private function open()
    {
        // Until the directory exists — `laravel new` creates it a few steps in — the
        // lines stay buffered, and the check is repeated only while nothing is open.
        if ($this->path === null || ! is_dir(dirname($this->path))) {
            return null;
        }

        $handle = fopen($this->path, 'a');

        if ($handle === false) {
            return null;
        }

        stream_set_write_buffer($handle, 0);

        return $this->handle = $handle;
    }

    private function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
