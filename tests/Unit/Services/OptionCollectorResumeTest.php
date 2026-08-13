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
        ->and($resumed->targetPath)->toBe($installerOption->targetPath);
});
