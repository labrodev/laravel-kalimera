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

it('publishes the providers of the selected packages', function (): void {
    $installerOption = makeInstallerOption([
        'additionalPackages' => ['spatie/laravel-data', 'spatie/laravel-permission'],
    ]);
    $processRunner = new FakeProcessRunner;

    makeAdditionalPackagesInstall(
        InstallerConfig::builtIn()->additionalPackages,
        $installerOption,
        $processRunner,
    )->execute();

    // The packages themselves are downloaded on the host by PackagesRequire.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan vendor:publish --provider=Spatie\LaravelData\LaravelDataServiceProvider --no-interaction',
        './vendor/bin/sail artisan vendor:publish --provider=Spatie\Permission\PermissionServiceProvider --no-interaction',
    ])
        ->and($processRunner->commands[0]['cwd'])->toBe($installerOption->targetPath);
});

it('publishes nothing for packages without providers', function (): void {
    $catalog = [
        new AdditionalPackage(label: 'medialibrary', package: 'spatie/laravel-medialibrary'),
        new AdditionalPackage(dev: true, label: 'debugbar', package: 'barryvdh/laravel-debugbar'),
    ];
    $installerOption = makeInstallerOption([
        'additionalPackages' => ['spatie/laravel-medialibrary', 'barryvdh/laravel-debugbar', 'vendor/forgotten'],
    ]);
    $processRunner = new FakeProcessRunner;

    makeAdditionalPackagesInstall($catalog, $installerOption, $processRunner)->execute();

    expect($processRunner->commands)->toBe([]);
});

it('names what it does now that it no longer installs anything', function (): void {
    $installerOption = makeInstallerOption();

    expect(makeAdditionalPackagesInstall([], $installerOption, new FakeProcessRunner)->label())
        ->toBe('Publishing additional package configuration');
});
