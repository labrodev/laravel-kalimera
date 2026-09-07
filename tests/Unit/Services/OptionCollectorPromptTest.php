<?php

declare(strict_types=1);

use Kalimera\Services\ConfigLoader;
use Kalimera\Services\OptionCollector;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

it('pre-selects every prompt from the loaded config', function (): void {
    // Enter through all 14 prompts — the collected answers must equal the config,
    // proving the pre-selection actually comes from it.
    Prompt::fake(array_fill(0, 14, Key::ENTER));

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
        ->and($collected->installBoost)->toBe($installerConfig->installBoost)
        ->and($collected->boostAgents)->toBe($installerConfig->boostAgents)
        ->and($collected->boostSkillRepos)->toBe($installerConfig->boostSkillRepos);
});

// Answering no to Boost has to take its follow-up questions with it: which agents to
// configure and which skill repositories to pull are meaningless once it is not installed,
// and leaving them answered would put a boostAgents list in the summary and the saved
// answers for a step the plan does not contain.
it('does not ask about agents or skill repositories when Boost is declined', function (): void {
    // Twelve prompts rather than fourteen: the Boost confirm is the tenth, and the two
    // questions behind it never appear. 'n' only toggles a confirm, so it needs its own
    // Enter to submit — after which just extraPackages and extraDevPackages are left.
    Prompt::fake([...array_fill(0, 9, Key::ENTER), 'n', Key::ENTER, Key::ENTER, Key::ENTER]);

    $collected = (new OptionCollector)(
        dryRun: true,
        installerConfig: (new ConfigLoader)(configPath: null),
        presetName: tempDir().'/demo-app',
        resume: false,
        useDefaults: false,
    );

    expect($collected->installBoost)->toBeFalse()
        ->and($collected->boostAgents)->toBe([])
        ->and($collected->boostSkillRepos)->toBe([]);
});
