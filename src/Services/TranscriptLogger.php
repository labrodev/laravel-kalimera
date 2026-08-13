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

    public function __construct(private ?string $path = null) {}

    /**
     * Point the transcript at a file. Everything buffered so far is written as soon as
     * the parent directory exists.
     */
    public function useFile(string $path): void
    {
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
        $this->write('Child process output is streamed to the terminal and captured below.');
    }

    public function step(int $index, string $label, int $total): void
    {
        $this->write(sprintf('Step %d/%d — %s', $index, $total, $label));
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
        if ($this->pending === '' || $this->path === null || ! is_dir(dirname($this->path))) {
            return;
        }

        file_put_contents(data: $this->pending, filename: $this->path, flags: FILE_APPEND | LOCK_EX);

        $this->pending = '';
    }
}
