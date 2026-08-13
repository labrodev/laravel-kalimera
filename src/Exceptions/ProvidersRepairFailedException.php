<?php

declare(strict_types=1);

namespace Kalimera\Exceptions;

use RuntimeException;
use Throwable;

class ProvidersRepairFailedException extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function make(string $path): self
    {
        return new self(sprintf('Unable to repair %s — fix it manually, then re-run with --continue.', $path));
    }
}
