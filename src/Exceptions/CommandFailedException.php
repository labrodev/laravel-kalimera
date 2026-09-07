<?php

declare(strict_types=1);

namespace Kalimera\Exceptions;

use RuntimeException;
use Throwable;

class CommandFailedException extends RuntimeException
{
    /**
     * @param  string  $output  The tail of what the command printed before it failed. Callers
     *                          use it to tell apart failures that a retry can cure from those
     *                          that need the environment repaired first.
     */
    private function __construct(string $message, public readonly string $output = '', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function make(string $exitCode, string $printable, string $output = ''): self
    {
        return new self(sprintf('Command failed (exit %s): %s', $exitCode, $printable), output: $output);
    }

    /**
     * A command that never got as far as an exit code — killed by its timeout, or by a
     * signal. It failed exactly as much as one that exited non-zero, so it arrives as the
     * same kind of failure: the retry budget and every `catch (CommandFailedException)`
     * recovery path key off this type, and a command that escaped past them would skip
     * both.
     */
    public static function aborted(string $reason, string $printable, string $output = '', ?Throwable $previous = null): self
    {
        return new self(sprintf('Command aborted (%s): %s', $reason, $printable), output: $output, previous: $previous);
    }
}
