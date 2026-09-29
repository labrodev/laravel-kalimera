<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Exceptions\ConcurrentRunException;
use Kalimera\Exceptions\InvalidTargetException;

class RunLock
{
    /** @var resource|null */
    private $handle;

    /**
     * Holds an exclusive advisory lock for the target directory so two kalimera
     * runs can never scaffold the same application concurrently — parked terminal
     * sessions and forgotten background runs corrupt composer.json and vendor/
     * when they race. The lock dies with the process, so it can never go stale.
     *
     * A fresh run also has the directory checked again once the lock is held. The first
     * check happens before the prompts, and a run parked at one of them can reach the lock
     * after another run created the directory and let go — scaffolding over that
     * application as if it were new would clear its containers and replay every step.
     */
    public function acquire(string $targetPath, bool $fresh = false): void
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

        if ($fresh && is_dir($targetPath)) {
            $this->release();

            throw InvalidTargetException::make(sprintf(
                'Directory %s was created by another run while this one was waiting. Re-run with --continue to resume there.',
                $targetPath,
            ));
        }
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
