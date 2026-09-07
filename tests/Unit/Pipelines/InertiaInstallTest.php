<?php

declare(strict_types=1);

use Kalimera\Pipelines\InertiaInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

it('requires inertia and publishes its middleware', function (): void {
    $processRunner = new FakeProcessRunner;

    new InertiaInstall(
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: tempDir().'/demo-app'),
    )->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require inertiajs/inertia-laravel',
        './vendor/bin/sail artisan inertia:middleware',
    ]);
});

it('retries the composer require, which reaches the network', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'composer require inertiajs/inertia-laravel', times: 2);

    new InertiaInstall(
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: tempDir().'/demo-app'),
    )->execute();

    // The middleware step is local and gets no retries, so it must appear exactly once.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require inertiajs/inertia-laravel',
        './vendor/bin/sail composer require inertiajs/inertia-laravel',
        './vendor/bin/sail composer require inertiajs/inertia-laravel',
        './vendor/bin/sail artisan inertia:middleware',
    ]);
});
