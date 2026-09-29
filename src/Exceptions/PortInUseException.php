<?php

declare(strict_types=1);

namespace Kalimera\Exceptions;

use RuntimeException;
use Throwable;

class PortInUseException extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * @param  array<string, int>  $busy  Port by the .env key that sets it
     */
    public static function make(array $busy, ?Throwable $previous = null): self
    {
        $ports = [];

        foreach ($busy as $key => $port) {
            $ports[] = sprintf('%d (%s)', $port, $key);
        }

        return new self(sprintf(
            'Sail could not start: something else on this machine is holding port %s. Stop whatever holds it, or set a free port for that key in .env, then re-run with --continue.',
            implode(', ', $ports),
        ), $previous);
    }
}
