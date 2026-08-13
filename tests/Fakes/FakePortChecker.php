<?php

declare(strict_types=1);

namespace Kalimera\Tests\Fakes;

use Kalimera\Contracts\PortChecker;

readonly class FakePortChecker implements PortChecker
{
    /**
     * @param  list<int>  $busy
     */
    public function __construct(private array $busy = []) {}

    public function isBusy(int $port): bool
    {
        return in_array($port, $this->busy, true);
    }
}
