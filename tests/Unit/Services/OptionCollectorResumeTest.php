<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerConfig;
use Kalimera\Services\InstallerOptionStore;
use Kalimera\Services\OptionCollector;

it('resumes with the saved answers instead of prompting again', function (): void {
    $installerOption = makeInstallerOption([
        'sailServices' => ['pgsql', 'redis', 'mailpit'],
        'starterKit' => 'vue',
    ]);
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    (new InstallerOptionStore)->save($installerOption);

    $resumed = (new OptionCollector)(
        dryRun: false,
        installerConfig: InstallerConfig::builtIn(),
        presetName: $installerOption->targetPath,
        resume: true,
        useDefaults: false,
    );

    expect($resumed->starterKit)->toBe('vue')
        ->and($resumed->sailServices)->toBe(['pgsql', 'redis', 'mailpit'])
        ->and($resumed->targetPath)->toBe($installerOption->targetPath)
        ->and($resumed->resume)->toBeTrue();
});

// Resuming into a directory that is not there scaffolds a brand-new application, so it
// must not inherit a resumed run's promise to leave the previous project's containers
// and volumes alone — SailStart reads this flag to decide exactly that.
it('does not count as a resume when the application directory is gone', function (): void {
    $targetPath = sys_get_temp_dir().'/kalimera-never-created-'.bin2hex(random_bytes(4));

    $collected = (new OptionCollector)(
        dryRun: false,
        installerConfig: InstallerConfig::builtIn(),
        presetName: $targetPath,
        resume: true,
        useDefaults: true,
    );

    expect($collected->resume)->toBeFalse()
        ->and($collected->targetPath)->toBe($targetPath);
});
