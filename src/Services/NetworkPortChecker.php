<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Contracts\PortChecker;

readonly class NetworkPortChecker implements PortChecker
{
    public function isBusy(int $port): bool
    {
        $connection = @fsockopen(hostname: '127.0.0.1', port: $port, timeout: 0.2);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
