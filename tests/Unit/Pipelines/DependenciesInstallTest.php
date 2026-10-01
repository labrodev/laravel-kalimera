<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\DependenciesInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeDependenciesInstall(InstallerOption $installerOption, FakeProcessRunner $processRunner): DependenciesInstall
{
    return new DependenciesInstall(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

// Vet's plugin audits every install against vet.json, so the file is recorded from the
// vendor/ the host already filled before the first command that runs with plugins on.
// No --minimum-release-age: a floor rejects releases younger than it even when trusted.
it('records the vet trust file before installing in the container', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;

    makeDependenciesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail php vendor/bin/vet --init --no-interaction',
        './vendor/bin/sail composer install --no-interaction',
        './vendor/bin/sail artisan vendor:publish --tag=laravel-assets --force --no-interaction',
    ])
        ->and(array_unique(array_column($processRunner->commands, 'cwd')))->toBe([$installerOption->targetPath]);
});

it('only installs when vet was not chosen', function (): void {
    $processRunner = new FakeProcessRunner;

    makeDependenciesInstall(makeInstallerOption(['qualityTools' => ['pint']]), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer install --no-interaction',
        './vendor/bin/sail artisan vendor:publish --tag=laravel-assets --force --no-interaction',
    ]);
});

it('only installs on a php version vet cannot run on', function (): void {
    $processRunner = new FakeProcessRunner;

    makeDependenciesInstall(makeInstallerOption(['phpConstraint' => '^8.3']), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer install --no-interaction',
        './vendor/bin/sail artisan vendor:publish --tag=laravel-assets --force --no-interaction',
    ]);
});

// The application is sound without vet.json; it can be recorded by hand, so the scaffold
// reports it and goes on rather than dying over it.
it('warns and still installs when the trust file cannot be recorded', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('vet --init');

    makeDependenciesInstall(makeInstallerOption(), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail php vendor/bin/vet --init --no-interaction',
        './vendor/bin/sail composer install --no-interaction',
        './vendor/bin/sail artisan vendor:publish --tag=laravel-assets --force --no-interaction',
    ])
        ->and(promptOutput())->toContain('Vet could not record the trust file');
});

// The skeleton's post-update-cmd, which neither half triggers: the host requires with
// --no-scripts and `install` is not an update. It boots the application, so it needs the
// vendor/ the install just finished.
it('publishes the laravel assets only once the install has finished', function (): void {
    $processRunner = new FakeProcessRunner;

    makeDependenciesInstall(makeInstallerOption(['qualityTools' => []]), $processRunner)->execute();

    $lines = $processRunner->commandLines();

    $install = array_search('./vendor/bin/sail composer install --no-interaction', $lines, true);
    $assets = array_search('./vendor/bin/sail artisan vendor:publish --tag=laravel-assets --force --no-interaction', $lines, true);

    expect($install)->toBeInt()
        ->and($assets)->toBe((int) $install + 1);
});

it('stops the scaffold when the install fails', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('composer install');

    expect(fn () => makeDependenciesInstall(makeInstallerOption(), $processRunner)->execute())
        ->toThrow(CommandFailedException::class)
        ->and($processRunner->commandLines())->not->toContain('./vendor/bin/sail artisan vendor:publish --tag=laravel-assets --force --no-interaction');
});
