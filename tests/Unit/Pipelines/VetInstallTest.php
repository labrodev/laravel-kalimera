<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\VetInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * @param  array<string, mixed>  $manifest
 */
function makeVetInstall(FakeProcessRunner $processRunner, array $manifest = [], ?InstallerOption $installerOption = null): VetInstall
{
    $installerOption ??= makeInstallerOption();

    if (! is_dir($installerOption->targetPath)) {
        mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    }

    file_put_contents(
        $installerOption->targetPath.'/composer.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    );

    return new VetInstall(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

/**
 * @return array<string, mixed>
 */
function vetManifest(InstallerOption $installerOption): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        associative: true,
        flags: JSON_THROW_ON_ERROR,
        json: (string) file_get_contents($installerOption->targetPath.'/composer.json'),
    );

    return $decoded;
}

it('requires the package and records what the scaffold installed', function (): void {
    $processRunner = new FakeProcessRunner;

    makeVetInstall($processRunner)->execute();

    // No --minimum-release-age: a floor rejects releases younger than it even when they are
    // trusted, so setting one here would hand over an application failing its own audit.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/vet --dev',
        './vendor/bin/sail php vendor/bin/vet --init --no-interaction',
    ]);
});

// Composer aborts on an unlisted composer-plugin instead of warning past it, so the entry
// arriving after the require would leave the package in the manifest and out of vendor/.
it('allows the plugin before requiring it', function (): void {
    $processRunner = new FakeProcessRunner;
    $installerOption = makeInstallerOption();

    makeVetInstall(processRunner: $processRunner, installerOption: $installerOption)->execute();

    expect($processRunner->fileActions[0])->toBe('allow the laravel/vet composer plugin in composer.json')
        ->and(vetManifest($installerOption)['config']['allow-plugins'])->toBe(['laravel/vet' => true]);
});

it('keeps the plugins an application already allows', function (): void {
    $installerOption = makeInstallerOption();

    makeVetInstall(
        processRunner: new FakeProcessRunner,
        manifest: ['config' => ['allow-plugins' => ['pestphp/pest-plugin' => true]]],
        installerOption: $installerOption,
    )->execute();

    expect(vetManifest($installerOption)['config']['allow-plugins'])->toBe([
        'pestphp/pest-plugin' => true,
        'laravel/vet' => true,
    ]);
});

it('registers the vet script and adds it to the existing quality gate', function (): void {
    $installerOption = makeInstallerOption();

    makeVetInstall(
        processRunner: new FakeProcessRunner,
        manifest: ['scripts' => ['quality' => ['@rector:dry', '@pint:dry', '@phpstan']]],
        installerOption: $installerOption,
    )->execute();

    expect(vetManifest($installerOption)['scripts'])->toBe([
        'quality' => ['@rector:dry', '@pint:dry', '@phpstan', '@vet'],
        'vet' => 'vendor/bin/vet',
    ]);
});

// A resumed run replays the whole step, and a quality gate listing @vet twice would run
// the audit twice for no reason.
it('does not add the vet gate twice when the step runs again', function (): void {
    $installerOption = makeInstallerOption();

    makeVetInstall(
        processRunner: new FakeProcessRunner,
        manifest: ['scripts' => ['quality' => ['@phpstan', '@vet'], 'vet' => 'vendor/bin/vet']],
        installerOption: $installerOption,
    )->execute();

    expect(vetManifest($installerOption)['scripts']['quality'])->toBe(['@phpstan', '@vet']);
});

// Vet declines to record while composer.lock and vendor/ disagree — a state an earlier
// composer flake can leave behind. The application is sound; vet.json just does not cover
// it yet, so the scaffold reports it and finishes rather than dying at the last step.
it('warns rather than failing when the trust file cannot be recorded', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('vet --init');
    $installerOption = makeInstallerOption();

    makeVetInstall(processRunner: $processRunner, installerOption: $installerOption)->execute();

    expect(promptOutput())->toContain('Vet could not record the trust file')
        ->and(vetManifest($installerOption)['scripts']['vet'])->toBe('vendor/bin/vet');
});

// The package has to be there: without it the `vet` script and the @vet gate registered
// below would both point at a binary that does not exist.
it('stops the scaffold when the package cannot be required', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('require laravel/vet');

    expect(fn () => makeVetInstall($processRunner)->execute())->toThrow(CommandFailedException::class);
});

it('touches no files during a dry run', function (): void {
    $installerOption = makeInstallerOption();

    makeVetInstall(
        processRunner: new FakeProcessRunner(dryRun: true),
        manifest: ['scripts' => ['quality' => ['@phpstan']]],
        installerOption: $installerOption,
    )->execute();

    expect(vetManifest($installerOption))->toBe(['scripts' => ['quality' => ['@phpstan']]]);
});

// Laravel runs on less than vet does, and the PHP prompt takes any constraint, so this is a
// combination a user can legitimately ask for.
it('skips itself on a php version vet cannot run on', function (): void {
    $processRunner = new FakeProcessRunner;
    $installerOption = makeInstallerOption(['phpConstraint' => '^8.3']);

    makeVetInstall(processRunner: $processRunner, installerOption: $installerOption)->execute();

    expect($processRunner->commands)->toBe([])
        ->and($processRunner->fileActions)->toBe([])
        ->and(promptOutput())->toContain('Vet requires PHP 8.4 or newer');
});
