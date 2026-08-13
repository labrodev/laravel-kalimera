<?php

declare(strict_types=1);

use Kalimera\Services\ConfigLoader;
use Kalimera\Services\OptionCollector;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

it('pre-selects every prompt from the loaded config', function (): void {
    // Enter through all 13 prompts — the collected answers must equal the config,
    // proving the pre-selection actually comes from it.
    Prompt::fake(array_fill(0, 13, Key::ENTER));

    $installerConfig = (new ConfigLoader)(configPath: null);

    $collected = (new OptionCollector)(
        dryRun: true,
        installerConfig: $installerConfig,
        presetName: tempDir().'/demo-app',
        resume: false,
        useDefaults: false,
    );

    expect($collected->starterKit)->toBe($installerConfig->starterKit)
        ->and($collected->aroundPackages)->toBe($installerConfig->aroundPackages)
        ->and($collected->sailServices)->toBe($installerConfig->sailServices)
        ->and($collected->phpConstraint)->toBe($installerConfig->phpConstraint)
        ->and($collected->qualityTools)->toBe($installerConfig->qualityTools)
        ->and($collected->additionalPackages)->toBe($installerConfig->preselectedAdditionalPackages())
        ->and($collected->coreNamespace)->toBe($installerConfig->coreNamespace)
        ->and($collected->installPostmark)->toBe($installerConfig->installPostmark)
        ->and($collected->boostAgents)->toBe($installerConfig->boostAgents)
        ->and($collected->boostSkillRepos)->toBe($installerConfig->boostSkillRepos);
});
