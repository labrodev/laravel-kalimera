<?php

declare(strict_types=1);

use Kalimera\Contracts\Pipeline;
use Kalimera\Exceptions\InvalidTargetException;
use Kalimera\Services\InstallerOptionStore;
use Kalimera\Services\StepCheckpoint;

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

// A plan rebuilt from fresh answers would still skip the steps that ran under the old
// ones, and finish an application that is half one configuration and half the other.
it('refuses to resume when the state file cannot be read', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera.json', '{not json');

    (new InstallerOptionStore)->load(appName: 'demo-app', dryRun: false, targetPath: $targetPath);
})->throws(InvalidTargetException::class, 'answers it should hold are missing or unreadable');

// What an earlier release left when it was interrupted between deleting the answers and
// deleting the checkpoint.
it('refuses to resume when steps were recorded but the answers are gone', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera-steps.json', '["AppCreate", "SailInstall"]');

    (new InstallerOptionStore)->load(appName: 'demo-app', dryRun: false, targetPath: $targetPath);
})->throws(InvalidTargetException::class);

it('keeps the recorded steps when the answers are saved again', function (): void {
    $installerOption = makeInstallerOption();
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);

    $step = new readonly class implements Pipeline
    {
        public function label(): string
        {
            return 'a step';
        }

        public function execute(): void {}
    };

    $stepCheckpoint = new StepCheckpoint($installerOption->targetPath);
    $stepCheckpoint->record($step);

    (new InstallerOptionStore)->save($installerOption);

    expect($stepCheckpoint->completed($step))->toBeTrue();
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

it('refuses to resume when the saved answers miss required keys', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.kalimera.json', '{"starterKit": "react"}');

    (new InstallerOptionStore)->load(appName: 'demo-app', dryRun: false, targetPath: $targetPath);
})->throws(InvalidTargetException::class);

it('keeps the invocation flags out of the state file', function (): void {
    $installerOption = makeInstallerOption(['dryRun' => true]);
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);

    (new InstallerOptionStore)->save($installerOption);

    $decoded = json_decode((string) file_get_contents($installerOption->targetPath.'/.kalimera.json'), true)['answers'];

    // Both come from the current run, so a stored value could only mislead a reader.
    expect($decoded)->not->toHaveKey('dryRun')
        ->and($decoded)->not->toHaveKey('resume')
        ->and($decoded)->toHaveKey('starterKit');
});
