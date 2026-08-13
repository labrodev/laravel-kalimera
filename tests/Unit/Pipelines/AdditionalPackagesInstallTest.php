<?php

declare(strict_types=1);

use Kalimera\Payloads\AdditionalPackage;
use Kalimera\Payloads\InstallerConfig;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\AdditionalPackagesInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * @param  list<AdditionalPackage>  $catalog
 */
function makeAdditionalPackagesInstall(array $catalog, InstallerOption $installerOption, FakeProcessRunner $processRunner): AdditionalPackagesInstall
{
    return new AdditionalPackagesInstall(
        catalog: $catalog,
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

it('requires the selected packages and publishes their providers', function (): void {
    $installerOption = makeInstallerOption([
        'additionalPackages' => ['spatie/laravel-data', 'spatie/laravel-permission'],
    ]);
    $processRunner = new FakeProcessRunner;

    makeAdditionalPackagesInstall(
        InstallerConfig::builtIn()->additionalPackages,
        $installerOption,
        $processRunner,
    )->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require spatie/laravel-data spatie/laravel-permission',
        './vendor/bin/sail artisan vendor:publish --provider=Spatie\LaravelData\LaravelDataServiceProvider --no-interaction',
        './vendor/bin/sail artisan vendor:publish --provider=Spatie\Permission\PermissionServiceProvider --no-interaction',
    ])
        ->and($processRunner->commands[0]['cwd'])->toBe($installerOption->targetPath);
});

it('splits dev packages into a separate require --dev command', function (): void {
    $catalog = [
        new AdditionalPackage(label: 'medialibrary', package: 'spatie/laravel-medialibrary'),
        new AdditionalPackage(dev: true, label: 'debugbar', package: 'barryvdh/laravel-debugbar'),
    ];
    $installerOption = makeInstallerOption([
        'additionalPackages' => ['spatie/laravel-medialibrary', 'barryvdh/laravel-debugbar'],
    ]);
    $processRunner = new FakeProcessRunner;

    makeAdditionalPackagesInstall($catalog, $installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require spatie/laravel-medialibrary',
        './vendor/bin/sail composer require --dev barryvdh/laravel-debugbar',
    ]);
});

it('installs a selected package missing from the catalog as a plain dependency', function (): void {
    $installerOption = makeInstallerOption(['additionalPackages' => ['vendor/forgotten']]);
    $processRunner = new FakeProcessRunner;

    makeAdditionalPackagesInstall([], $installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require vendor/forgotten',
    ]);
});
