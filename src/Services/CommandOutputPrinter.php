<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Symfony\Component\Console\Terminal;

/**
 * Child processes — the Sail image build above all — emit tens of thousands of apt, npm
 * and composer lines that bury the installer's own steps. The whole stream still reaches
 * the transcript; the terminal gets a single rewritten progress line, and the tail of a
 * failed command so a broken run stays diagnosable without re-running under --verbose.
 */
class CommandOutputPrinter
{
    /**
     * How much of a failed command's output is replayed to the terminal.
     */
    private const int TAIL_LINES = 40;

    private const float FRAME_SECONDS = 0.12;

    /** @var list<string> */
    private const array SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * Applied in order, and the order is load-bearing: the catch-all's final-byte range
     * includes `[` (0x5B), so running it first would eat a CSI introducer and strand its
     * parameters on screen as visible text.
     *
     * @var list<string>
     */
    private const array ESCAPE_PATTERNS = [
        '/\033\][^\007\033]*(?:\007|\033\\\\)/', // OSC — window titles, hyperlinks
        '/\033\[[0-9;?]*[ -\/]*[@-~]/',          // CSI — colour, cursor movement
        '/\033[ -\/]*[0-~]/',                    // the rest, e.g. the ESC(B charset select
    ];

    /** @var list<string> */
    private array $tail = [];

    private string $buffer = '';

    private string $latest = '';

    private int $frame = 0;

    private float $startedAt = 0.0;

    private float $paintedAt = 0.0;

    private bool $painted = false;

    private readonly bool $interactive;

    /** @var resource */
    private $stream;

    /** @var resource */
    private $errorStream;

    /**
     * @param  resource|null  $stream
     * @param  resource|null  $errorStream
     */
    public function __construct(
        private readonly bool $verbose = false,
        ?bool $interactive = null,
        $stream = null,
        $errorStream = null,
        private readonly float $heartbeatSeconds = 30.0,
    ) {
        $this->stream = $stream ?? STDOUT;
        $this->errorStream = $errorStream ?? STDERR;

        // A rewritten status line only makes sense on a terminal: piped into a file or a
        // CI log the escape codes are noise, so those get a periodic heartbeat instead.
        $this->interactive = $interactive ?? stream_isatty($this->stream);
    }

    public function begin(): void
    {
        $this->tail = [];
        $this->buffer = '';
        $this->latest = '';
        $this->frame = 0;
        $this->startedAt = microtime(true);
        $this->paintedAt = $this->startedAt;
        $this->painted = false;
    }

    public function write(string $buffer, bool $error = false): void
    {
        $this->buffer .= $buffer;

        while (($break = strpos($this->buffer, "\n")) !== false) {
            $this->collect(substr($this->buffer, 0, $break));
            $this->buffer = substr($this->buffer, $break + 1);
        }

        // The tail is collected either way: verbose only changes what reaches the
        // terminal, never whether the caller can inspect why a command failed.
        if ($this->verbose) {
            fwrite($error ? $this->errorStream : $this->stream, $buffer);

            return;
        }

        $this->paint();
    }

    /**
     * Advance the status line without new content. A child can go quiet for a long time —
     * a 30 MB download prints nothing until it lands — and a progress indicator that only
     * moves when output arrives stops indicating anything exactly when it matters.
     */
    public function tick(): void
    {
        if ($this->verbose) {
            return;
        }

        $this->paint();
    }

    /**
     * Returns what the command printed just before it stopped, for a caller that has to
     * tell one kind of failure from another. Handing it back here rather than exposing a
     * getter means it cannot be read from a command the printer never saw.
     *
     * @param  bool  $replay  Whether a failure should also put its tail on the terminal.
     *                        A caller with another attempt to go passes false: the tail is
     *                        still returned and still logged, it just does not get printed
     *                        once per attempt. The return value never depends on this.
     */
    public function finish(bool $success, bool $replay = true): string
    {
        if ($this->buffer !== '') {
            $this->collect($this->buffer);
            $this->buffer = '';
        }

        $tail = implode(PHP_EOL, $this->tail);

        if ($this->verbose) {
            return $tail;
        }

        $this->erase();

        if ($success || ! $replay || $this->tail === []) {
            return $tail;
        }

        fwrite($this->errorStream, PHP_EOL.'   Last '.count($this->tail).' lines of output:'.PHP_EOL);

        foreach ($this->tail as $line) {
            fwrite($this->errorStream, '   │ '.$line.PHP_EOL);
        }

        fwrite($this->errorStream, PHP_EOL);

        return $tail;
    }

    private function collect(string $line): void
    {
        $line = $this->readable($line);

        if ($line === '') {
            return;
        }

        $this->tail[] = $line;

        if (count($this->tail) > self::TAIL_LINES) {
            array_shift($this->tail);
        }

        $this->latest = $line;
    }

    private function paint(): void
    {
        $now = microtime(true);

        if (! $this->interactive) {
            if ($now - $this->paintedAt < $this->heartbeatSeconds) {
                return;
            }

            $this->paintedAt = $now;

            fwrite($this->stream, sprintf('   … %s  %s%s', $this->elapsed($now), $this->latest, PHP_EOL));

            return;
        }

        if ($now - $this->paintedAt < self::FRAME_SECONDS) {
            return;
        }

        $this->paintedAt = $now;

        $status = sprintf(
            '%s %s  %s',
            self::SPINNER[$this->frame++ % count(self::SPINNER)],
            $this->elapsed($now),
            $this->latest,
        );

        fwrite($this->stream, "\r\033[2K   ".$this->clamp($status));

        $this->painted = true;
    }

    /**
     * Leave the status line's row empty so whatever prints next starts on a clean line.
     */
    private function erase(): void
    {
        if (! $this->painted) {
            return;
        }

        fwrite($this->stream, "\r\033[2K");

        $this->painted = false;
    }

    /**
     * Docker and npm paint with ANSI escapes and carriage returns. Only the last segment
     * of a rewritten line carries meaning, and the escapes would corrupt the status line.
     */
    private function readable(string $line): string
    {
        $line = (string) preg_replace(self::ESCAPE_PATTERNS, '', $line);

        $position = strrpos($line, "\r");

        if ($position !== false) {
            $line = substr($line, $position + 1);
        }

        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line));
    }

    private function clamp(string $status): string
    {
        $width = max(40, new Terminal()->getWidth() - 6);

        return mb_strlen($status) <= $width ? $status : mb_substr($status, 0, $width - 1).'…';
    }

    private function elapsed(float $now): string
    {
        $seconds = (int) max(0, $now - $this->startedAt);

        return $seconds < 60
            ? $seconds.'s'
            : sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
    }
}
