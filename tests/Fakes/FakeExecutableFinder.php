<?php

declare(strict_types=1);

namespace Kalimera\Tests\Fakes;

use Override;
use Symfony\Component\Process\ExecutableFinder;

class FakeExecutableFinder extends ExecutableFinder
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(private readonly array $missing = []) {}

    /**
     * @param  string[]  $extraDirs
     */
    #[Override]
    public function find(string $name, ?string $default = null, array $extraDirs = []): ?string
    {
        if (in_array($name, $this->missing, true)) {
            return null;
        }

        return '/usr/bin/'.$name;
    }
}
