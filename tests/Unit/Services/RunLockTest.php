<?php

declare(strict_types=1);

use Kalimera\Exceptions\ConcurrentRunException;
use Kalimera\Exceptions\InvalidTargetException;
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

// The run that checked "does not exist" before its prompts can reach the lock after
// another run created the directory and let go of it.
it('refuses a fresh run once the directory appeared before the lock was won', function (): void {
    $targetPath = tempDir().'/demo-app';
    mkdir($targetPath);

    expect(fn () => (new RunLock)->acquire(fresh: true, targetPath: $targetPath))
        ->toThrow(InvalidTargetException::class, 'created by another run');
});

it('lets go of the lock when it refuses, so the resume it suggests can take it', function (): void {
    $targetPath = tempDir().'/demo-app';
    mkdir($targetPath);

    try {
        (new RunLock)->acquire(fresh: true, targetPath: $targetPath);
    } catch (InvalidTargetException) {
    }

    $runLock = new RunLock;
    $runLock->acquire($targetPath);
    $runLock->release();

    expect(true)->toBeTrue();
});

it('lets a resume into an existing directory through', function (): void {
    $targetPath = tempDir().'/demo-app';
    mkdir($targetPath);

    $runLock = new RunLock;
    $runLock->acquire($targetPath);
    $runLock->release();

    expect(true)->toBeTrue();
});
