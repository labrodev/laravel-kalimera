<?php

declare(strict_types=1);

use Kalimera\Contracts\Pipeline;
use Kalimera\KalimeraInstaller;
use Kalimera\Pipelines\SailPortsConfigure;
use Kalimera\Services\ComposerFileEditor;
use Kalimera\Services\RunLock;
use Kalimera\Services\TranscriptLogger;

arch('kalimera declares strict types everywhere', function (): void {
    expect('Kalimera')->toUseStrictTypes();
});

arch('kalimera classes are readonly', function (): void {
    expect('Kalimera')->classes()->toBeReadonly()
        ->ignoring([SailPortsConfigure::class, ComposerFileEditor::class, RunLock::class, TranscriptLogger::class, 'Kalimera\Exceptions', 'Kalimera\Tests']);
});

arch('kalimera never uses debug or output functions', function (): void {
    expect('Kalimera')->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'echo', 'print', 'exit', 'die']);
});

arch('pipelines implement the pipeline contract', function (): void {
    expect('Kalimera\Pipelines')->classes()->toImplement(Pipeline::class);
});

arch('pipelines expose an execute method', function (): void {
    expect('Kalimera\Pipelines')->classes()->toHaveMethod('execute');
});

arch('services do not depend on the orchestration layer', function (): void {
    expect('Kalimera\Services')->not->toUse([
        KalimeraInstaller::class,
        'Kalimera\Pipelines',
    ]);
});

arch('payloads are pure data', function (): void {
    expect('Kalimera\Payloads')->not->toUse([
        'Kalimera\Pipelines',
        'Kalimera\Services',
    ]);
});

arch('contracts are interfaces', function (): void {
    expect('Kalimera\Contracts')->toBeInterfaces();
});
