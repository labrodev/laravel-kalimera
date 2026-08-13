<?php

declare(strict_types=1);

namespace Kalimera\Exceptions;

use RuntimeException;
use Throwable;

class CommandFailedException extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function make(string $exitCode, string $printable): self
    {
        return new self(sprintf('Command failed (exit %s): %s', $exitCode, $printable));
    }
}
