<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\AgentGuardConfigure;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeAgentGuardApp(InstallerOption $installerOption, string $providers = "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n"): void
{
    mkdir(directory: $installerOption->targetPath.'/bootstrap', permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/bootstrap/providers.php', $providers);
}

it('publishes the provider and registers it in bootstrap/providers.php', function (): void {
    $installerOption = makeInstallerOption();
    makeAgentGuardApp($installerOption);
    $processRunner = new FakeProcessRunner;

    new AgentGuardConfigure(installerOption: $installerOption, processRunner: $processRunner)->execute();

    $provider = $installerOption->targetPath.'/app/Providers/AgentGuardServiceProvider.php';
    $providers = (string) file_get_contents($installerOption->targetPath.'/bootstrap/providers.php');

    expect(file_exists($provider))->toBeTrue()
        ->and($providers)->toContain('App\\Providers\\AgentGuardServiceProvider::class,')
        ->and($providers)->toContain('App\\Providers\\AppServiceProvider::class,');
});

it('registers the provider last so AppServiceProvider cannot reset the guard', function (): void {
    $installerOption = makeInstallerOption();
    makeAgentGuardApp($installerOption, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n    App\\Providers\\HorizonServiceProvider::class,\n];\n");

    new AgentGuardConfigure(installerOption: $installerOption, processRunner: new FakeProcessRunner)->execute();

    preg_match_all(
        matches: $matches,
        pattern: '/^\s+([A-Za-z0-9_\\\\]+)::class,$/m',
        subject: (string) file_get_contents($installerOption->targetPath.'/bootstrap/providers.php'),
    );

    expect(end($matches[1]))->toBe('App\\Providers\\AgentGuardServiceProvider');
});

it('guards the detector behind class_exists so a --no-dev deploy cannot fatal', function (): void {
    $installerOption = makeInstallerOption();
    makeAgentGuardApp($installerOption);

    new AgentGuardConfigure(installerOption: $installerOption, processRunner: new FakeProcessRunner)->execute();

    $provider = (string) file_get_contents($installerOption->targetPath.'/app/Providers/AgentGuardServiceProvider.php');

    expect($provider)->toContain('class_exists(AgentDetector::class)')
        ->and($provider)->toContain('prohibitDestructiveCommands');
});

it('leaves bootstrap/providers.php untouched when the provider is already registered', function (): void {
    $installerOption = makeInstallerOption();
    makeAgentGuardApp($installerOption, "<?php\n\nreturn [\n    App\\Providers\\AgentGuardServiceProvider::class,\n];\n");

    new AgentGuardConfigure(installerOption: $installerOption, processRunner: new FakeProcessRunner)->execute();

    $providers = (string) file_get_contents($installerOption->targetPath.'/bootstrap/providers.php');

    expect(substr_count($providers, 'AgentGuardServiceProvider::class,'))->toBe(1);
});

it('writes nothing during a dry run', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner(dryRun: true);

    new AgentGuardConfigure(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect(is_dir($installerOption->targetPath))->toBeFalse()
        ->and($processRunner->fileActions)->toBe([
            'publish app/Providers/AgentGuardServiceProvider.php',
            'register AgentGuardServiceProvider in bootstrap/providers.php',
        ]);
});
