<?php

declare(strict_types=1);

use Kalimera\Exceptions\ConcurrentRunException;
use Kalimera\Services\RunLock;

it('acquires and releases the lock for a target path', function (): void {
    $targetPath = tempDir().'/demo-app';

    $runLock = new RunLock;
    $runLock->acquire($targetPath);
    $runLock->release();

    $secondRunLock = new RunLock;
    $secondRunLock->acquire($targetPath);
    $secondRunLock->release();

    expect(true)->toBeTrue();
});

it('rejects a second concurrent run on the same target path', function (): void {
    $targetPath = tempDir().'/demo-app';

    $firstRunLock = new RunLock;
    $firstRunLock->acquire($targetPath);

    expect(fn () => (new RunLock)->acquire($targetPath))
        ->toThrow(ConcurrentRunException::class, $targetPath);

    $firstRunLock->release();
});

it('allows different target paths to run concurrently', function (): void {
    $firstRunLock = new RunLock;
    $secondRunLock = new RunLock;

    $firstRunLock->acquire(tempDir().'/first-app');
    $secondRunLock->acquire(tempDir().'/second-app');

    $firstRunLock->release();
    $secondRunLock->release();

    expect(true)->toBeTrue();
});
