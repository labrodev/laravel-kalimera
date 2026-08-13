<?php

declare(strict_types=1);

use Kalimera\Pipelines\SailRuntimeConfigure;
use Kalimera\Tests\Fakes\FakeProcessRunner;

it('pins the compose file to the selected sail runtime', function (): void {
    $installerOption = makeInstallerOption(['phpConstraint' => '^8.5']);
    $processRunner = new FakeProcessRunner;

    mkdir(directory: $installerOption->targetPath.'/vendor/laravel/sail/runtimes/8.5', permissions: 0755, recursive: true);
    file_put_contents(
        $installerOption->targetPath.'/compose.yaml',
        "services:\n    laravel.test:\n        build:\n            context: ./vendor/laravel/sail/runtimes/8.4\n        image: sail-8.4/app\n",
    );

    new SailRuntimeConfigure(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect(file_get_contents($installerOption->targetPath.'/compose.yaml'))->toBe(
        "services:\n    laravel.test:\n        build:\n            context: ./vendor/laravel/sail/runtimes/8.5\n        image: sail-8.5/app\n",
    );
});

it('keeps the compose file untouched when sail does not ship the runtime', function (): void {
    $installerOption = makeInstallerOption(['phpConstraint' => '^8.5']);
    $processRunner = new FakeProcessRunner;
    $contents = "services:\n    laravel.test:\n        image: sail-8.4/app\n";

    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/compose.yaml', $contents);

    new SailRuntimeConfigure(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect(file_get_contents($installerOption->targetPath.'/compose.yaml'))->toBe($contents);
});

it('does nothing when no compose file exists', function (): void {
    $installerOption = makeInstallerOption(['phpConstraint' => '^8.5']);
    $processRunner = new FakeProcessRunner;

    mkdir(directory: $installerOption->targetPath.'/vendor/laravel/sail/runtimes/8.5', permissions: 0755, recursive: true);

    new SailRuntimeConfigure(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->fileActions)->toBe(['pin the compose file to the PHP 8.5 Sail runtime'])
        ->and(glob($installerOption->targetPath.'/*.yaml'))->toBe([]);
});
