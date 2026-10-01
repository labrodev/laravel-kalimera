<?php

declare(strict_types=1);

use Kalimera\Pipelines\InertiaInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

it('publishes the inertia middleware', function (): void {
    $processRunner = new FakeProcessRunner;

    new InertiaInstall(
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: tempDir().'/demo-app'),
    )->execute();

    // inertiajs/inertia-laravel itself is downloaded on the host by PackagesRequire.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan inertia:middleware',
    ]);
});
