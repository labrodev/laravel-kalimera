<?php

declare(strict_types=1);

it('uses a database service when mysql is selected', function (): void {
    expect(makeInstallerOption(['sailServices' => ['mysql', 'redis']])->usesDatabaseService())->toBeTrue();
});

it('uses a database service when pgsql is selected', function (): void {
    expect(makeInstallerOption(['sailServices' => ['pgsql']])->usesDatabaseService())->toBeTrue();
});

it('uses no database service without mysql or pgsql', function (): void {
    expect(makeInstallerOption(['sailServices' => ['redis', 'mailpit']])->usesDatabaseService())->toBeFalse();
});

it('extracts the minor version from a caret constraint', function (): void {
    expect(makeInstallerOption(['phpConstraint' => '^8.5'])->phpMinorVersion())->toBe('8.5');
});

it('extracts the minor version from a tilde patch constraint', function (): void {
    expect(makeInstallerOption(['phpConstraint' => '~8.4.0'])->phpMinorVersion())->toBe('8.4');
});

it('falls back to the default minor version for an unparsable constraint', function (): void {
    expect(makeInstallerOption(['phpConstraint' => 'garbage'])->phpMinorVersion())->toBe('8.5');
});

it('wants horizon when it is among the around packages', function (): void {
    expect(makeInstallerOption(['aroundPackages' => ['horizon', 'fortify']])->wantsHorizon())->toBeTrue()
        ->and(makeInstallerOption(['aroundPackages' => ['fortify']])->wantsHorizon())->toBeFalse();
});

it('wants a quality tool only when it was selected', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => ['pint', 'rector']]);

    expect($installerOption->wantsQualityTool('pint'))->toBeTrue()
        ->and($installerOption->wantsQualityTool('phpstan'))->toBeFalse();
});

it('wants an additional package only when it was selected', function (): void {
    $installerOption = makeInstallerOption(['additionalPackages' => ['spatie/laravel-data']]);

    expect($installerOption->wantsAdditionalPackage('spatie/laravel-data'))->toBeTrue()
        ->and($installerOption->wantsAdditionalPackage('spatie/laravel-permission'))->toBeFalse();
});

// Vet is chosen at the quality-tools prompt but installed by its own step at the end of
// the plan, so a run that wants nothing else must not schedule QualityToolsInstall — its
// `composer require --dev` would have no packages to name.
it('does not count vet as static analysis', function (): void {
    expect(makeInstallerOption(['qualityTools' => ['vet']])->wantsStaticAnalysis())->toBeFalse()
        ->and(makeInstallerOption(['qualityTools' => ['pint', 'vet']])->wantsStaticAnalysis())->toBeTrue()
        ->and(makeInstallerOption(['qualityTools' => []])->wantsStaticAnalysis())->toBeFalse();
});
