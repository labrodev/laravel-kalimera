<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\BoostInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeBoostInstall(InstallerOption $installerOption, FakeProcessRunner $processRunner): BoostInstall
{
    return new BoostInstall(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

it('adds every configured skill repository', function (): void {
    $installerOption = makeInstallerOption([
        'boostAgents' => [],
        'boostSkillRepos' => ['labrodev/skills', 'https://github.com/owner/repo'],
    ]);
    $processRunner = new FakeProcessRunner;

    makeBoostInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/boost --dev',
        './vendor/bin/sail artisan boost:install --no-interaction',
        './vendor/bin/sail artisan boost:add-skill labrodev/skills --all --no-interaction',
        './vendor/bin/sail artisan boost:add-skill https://github.com/owner/repo --all --no-interaction',
        './vendor/bin/sail artisan boost:update --no-discover --no-interaction',
    ]);
});

it('continues with the remaining repositories when one cannot be added', function (): void {
    $installerOption = makeInstallerOption([
        'boostAgents' => [],
        'boostSkillRepos' => ['owner/typo', 'labrodev/skills'],
    ]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('owner/typo');

    makeBoostInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/boost --dev',
        './vendor/bin/sail artisan boost:install --no-interaction',
        './vendor/bin/sail artisan boost:add-skill owner/typo --all --no-interaction',
        './vendor/bin/sail artisan boost:add-skill labrodev/skills --all --no-interaction',
        './vendor/bin/sail artisan boost:update --no-discover --no-interaction',
    ]);
});

it('skips the skill step when no repository was given', function (): void {
    $installerOption = makeInstallerOption(['boostAgents' => [], 'boostSkillRepos' => []]);
    $processRunner = new FakeProcessRunner;

    makeBoostInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/boost --dev',
        './vendor/bin/sail artisan boost:install --no-interaction',
        './vendor/bin/sail artisan boost:update --no-discover --no-interaction',
    ]);
});

it('preconfigures boost.json with the chosen agents', function (): void {
    $installerOption = makeInstallerOption([
        'boostAgents' => ['claude_code', 'cursor'],
        'boostSkillRepos' => [],
    ]);
    $processRunner = new FakeProcessRunner;

    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);

    makeBoostInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/boost --dev',
        './vendor/bin/sail artisan boost:install --guidelines --skills --mcp --no-interaction',
        './vendor/bin/sail artisan boost:update --no-discover --no-interaction',
    ])
        ->and(json_decode((string) file_get_contents($installerOption->targetPath.'/boost.json'), true))
        ->toBe(['agents' => ['claude_code', 'cursor']]);
});

it('keeps the bundled guidance when the update fails', function (): void {
    $installerOption = makeInstallerOption(['boostAgents' => [], 'boostSkillRepos' => []]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('boost:update');

    makeBoostInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/boost --dev',
        './vendor/bin/sail artisan boost:install --no-interaction',
        './vendor/bin/sail artisan boost:update --no-discover --no-interaction',
    ]);
});
