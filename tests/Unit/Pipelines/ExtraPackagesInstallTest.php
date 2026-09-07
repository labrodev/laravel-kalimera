<?php

declare(strict_types=1);

use Kalimera\Pipelines\ExtraPackagesInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeExtraPackagesInstall(array $overrides, FakeProcessRunner $processRunner): ExtraPackagesInstall
{
    $installerOption = makeInstallerOption($overrides);

    return new ExtraPackagesInstall(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

it('requires runtime packages before dev packages', function (): void {
    $processRunner = new FakeProcessRunner;

    makeExtraPackagesInstall([
        'extraPackages' => ['spatie/laravel-sluggable', 'league/flysystem-aws-s3-v3'],
        'extraDevPackages' => ['barryvdh/laravel-debugbar'],
    ], $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require spatie/laravel-sluggable',
        './vendor/bin/sail composer require league/flysystem-aws-s3-v3',
        './vendor/bin/sail composer require --dev barryvdh/laravel-debugbar',
    ]);
});

it('skips a package that will not install and carries on with the rest', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('composer require does/not-exist');

    makeExtraPackagesInstall([
        'extraPackages' => ['does/not-exist', 'spatie/laravel-data'],
        'extraDevPackages' => [],
    ], $processRunner)->execute();

    // A typo in an optional extra must not cost the user the whole scaffold.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require does/not-exist',
        './vendor/bin/sail composer require spatie/laravel-data',
    ]);
});

it('runs nothing when no extras were chosen', function (): void {
    $processRunner = new FakeProcessRunner;

    makeExtraPackagesInstall(['extraPackages' => [], 'extraDevPackages' => []], $processRunner)->execute();

    expect($processRunner->commands)->toBe([]);
});
