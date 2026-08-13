<?php

declare(strict_types=1);

namespace Kalimera\Exceptions;

use RuntimeException;
use Throwable;

class TemplateMissingException extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function make(string $path): self
    {
        return new self(sprintf('Template %s is missing.', $path));
    }
}
