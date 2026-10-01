<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\VetInstall;
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

// The require happens on the host with the rest (PackagesRequire) and --init in the
// container (DependenciesInstall); this step only prepares composer.json for them.
it('edits composer.json and runs nothing', function (): void {
    $processRunner = new FakeProcessRunner;

    makeVetInstall($processRunner)->execute();

    expect($processRunner->commands)->toBe([])
        ->and($processRunner->fileActions)->toBe(['allow the laravel/vet composer plugin, add the vet script and @vet to quality']);
});

// Composer aborts on an unlisted composer-plugin instead of warning past it, so the entry
// has to be in place before the first command that runs with plugins on.
it('allows the plugin', function (): void {
    $installerOption = makeInstallerOption();

    makeVetInstall(processRunner: new FakeProcessRunner, installerOption: $installerOption)->execute();

    expect(vetManifest($installerOption)['config']['allow-plugins'])->toBe(['laravel/vet' => true]);
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
