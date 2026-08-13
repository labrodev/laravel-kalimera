<?php

declare(strict_types=1);

namespace Kalimera\Contracts;

interface PortChecker
{
    public function isBusy(int $port): bool;
}
