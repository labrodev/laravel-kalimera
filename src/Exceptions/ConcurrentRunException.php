<?php

declare(strict_types=1);

namespace Kalimera\Exceptions;

use RuntimeException;
use Throwable;

class ConcurrentRunException extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function make(string $targetPath): self
    {
        return new self(sprintf(
            'Another kalimera run is already working on %s. Close the other terminal (or kill the other kalimera process) and try again.',
            $targetPath,
        ));
    }
}
