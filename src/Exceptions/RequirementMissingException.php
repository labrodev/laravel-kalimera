<?php

declare(strict_types=1);

namespace Kalimera\Exceptions;

use RuntimeException;
use Throwable;

class RequirementMissingException extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function make(string $requirement, string $reason): self
    {
        return new self(sprintf('%s %s', $requirement, $reason));
    }
}
