<?php

declare(strict_types=1);

use Kalimera\Services\InstallerOptionStore;

it('round-trips the installer answers through the state file', function (): void {
    $installerOption = makeInstallerOption([
        'coreNamespace' => 'Aviadigit\\Core',
        'sailServices' => ['pgsql', 'redis', 'mailpit'],
        'additionalPackages' => ['spatie/laravel-data'],
    ]);
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);

    $installerOptionStore = new InstallerOptionStore;
    $installerOptionStore->save($installerOption);

    $loaded = $installerOptionStore->load(
        appName: $installerOption->appName,
        dryRun: false,
        targetPath: $installerOption->targetPath,
    );

    expect($loaded?->starterKit)->toBe('react')
        ->and($loaded?->sailServices)->toBe(['pgsql', 'redis', 'mailpit'])
        ->and($loaded?->additionalPackages)->toBe(['spatie/laravel-data'])
        ->and($loaded?->coreNamespace)->toBe('Aviadigit\\Core');
});

it('overrides the stored dry-run flag with the current invocation', function (): void {
    $installerOption = makeInstallerOption(['dryRun' => false]);
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);

    $installerOptionStore = new InstallerOptionStore;
    $installerOptionStore->save($installerOption);

    $loaded = $installerOptionStore->load(
        appName: $installerOption->appName,
        dryRun: true,
        targetPath: $installerOption->targetPath,
    );

    expect($loaded?->dryRun)->toBeTrue();
});

it('returns null when no state file exists', function (): void {
    $loaded = (new InstallerOptionStore)->load(appName: 'demo-app', dryRun: false, targetPath: tempDir());

    expect($loaded)->toBeNull();
});

it('returns null when the state file is corrupted', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera.json', '{not json');

    $loaded = (new InstallerOptionStore)->load(appName: 'demo-app', dryRun: false, targetPath: $targetPath);

    expect($loaded)->toBeNull();
});

it('reads the legacy spatiePackages key from older state files', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera.json', json_encode([
        'starterKit' => 'react',
        'phpConstraint' => '^8.5',
        'spatiePackages' => ['spatie/laravel-data'],
    ]));

    $loaded = (new InstallerOptionStore)->load(appName: 'demo-app', dryRun: false, targetPath: $targetPath);

    expect($loaded?->additionalPackages)->toBe(['spatie/laravel-data']);
});

it('returns null when the state file misses required keys', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera.json', '{"starterKit": "react"}');

    $loaded = (new InstallerOptionStore)->load(appName: 'demo-app', dryRun: false, targetPath: $targetPath);

    expect($loaded)->toBeNull();
});

it('forgets the state file', function (): void {
    $installerOption = makeInstallerOption();
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);

    $installerOptionStore = new InstallerOptionStore;
    $installerOptionStore->save($installerOption);
    $installerOptionStore->forget($installerOption->targetPath);

    expect(file_exists($installerOption->targetPath.'/.kalimera.json'))->toBeFalse();
});

it('forgetting a missing state file is a no-op', function (): void {
    (new InstallerOptionStore)->forget(tempDir());

    expect(true)->toBeTrue();
});

it('keeps the invocation flags out of the state file', function (): void {
    $installerOption = makeInstallerOption(['dryRun' => true]);
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);

    (new InstallerOptionStore)->save($installerOption);

    $decoded = json_decode((string) file_get_contents($installerOption->targetPath.'/.kalimera.json'), true);

    // Both come from the current run, so a stored value could only mislead a reader.
    expect($decoded)->not->toHaveKey('dryRun')
        ->and($decoded)->not->toHaveKey('resume')
        ->and($decoded)->toHaveKey('starterKit');
});
