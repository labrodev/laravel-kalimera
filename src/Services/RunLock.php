<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Exceptions\ConcurrentRunException;

class RunLock
{
    /** @var resource|null */
    private $handle;

    /**
     * Holds an exclusive advisory lock for the target directory so two kalimera
     * runs can never scaffold the same application concurrently — parked terminal
     * sessions and forgotten background runs corrupt composer.json and vendor/
     * when they race. The lock dies with the process, so it can never go stale.
     */
    public function acquire(string $targetPath): void
    {
        $handle = fopen($this->path($targetPath), 'c');

        if ($handle === false) {
            return;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw ConcurrentRunException::make($targetPath);
        }

        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    private function path(string $targetPath): string
    {
        return sys_get_temp_dir().'/kalimera-'.md5($targetPath).'.lock';
    }
}
