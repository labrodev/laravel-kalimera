<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\QualityToolsInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeQualityToolsInstall(InstallerOption $installerOption, FakeProcessRunner $processRunner): QualityToolsInstall
{
    if (! is_dir($installerOption->targetPath)) {
        mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    }

    if (! file_exists($installerOption->targetPath.'/composer.json')) {
        file_put_contents($installerOption->targetPath.'/composer.json', '{}');
    }

    return new QualityToolsInstall(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

/**
 * @return array<string, mixed>
 */
function qualityComposerScripts(InstallerOption $installerOption): array
{
    /** @var array{scripts?: array<string, mixed>} $decoded */
    $decoded = json_decode(
        associative: true,
        flags: JSON_THROW_ON_ERROR,
        json: (string) file_get_contents($installerOption->targetPath.'/composer.json'),
    );

    return $decoded['scripts'] ?? [];
}

it('requires the selected quality packages through sail', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => ['pint', 'phpstan', 'rector']]);
    $processRunner = new FakeProcessRunner;

    makeQualityToolsInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require --dev laravel/pint larastan/larastan barryvdh/laravel-ide-helper rector/rector driftingly/rector-laravel',
    ]);
});

it('strips the src paths from the published configs when no core structure is scaffolded', function (): void {
    $installerOption = makeInstallerOption(['coreNamespace' => null, 'qualityTools' => ['pint', 'phpstan', 'rector']]);
    $processRunner = new FakeProcessRunner;

    makeQualityToolsInstall($installerOption, $processRunner)->execute();

    expect((string) file_get_contents($installerOption->targetPath.'/phpstan.neon.dist'))->not->toContain('- src')
        ->and((string) file_get_contents($installerOption->targetPath.'/rector.php'))->not->toContain("__DIR__.'/src'");
});

it('keeps the src paths in the published configs when the core structure is scaffolded', function (): void {
    $installerOption = makeInstallerOption(['coreNamespace' => 'Core', 'qualityTools' => ['pint', 'phpstan', 'rector']]);
    $processRunner = new FakeProcessRunner;

    makeQualityToolsInstall($installerOption, $processRunner)->execute();

    expect((string) file_get_contents($installerOption->targetPath.'/phpstan.neon.dist'))->toContain("        - src\n")
        ->and((string) file_get_contents($installerOption->targetPath.'/rector.php'))->toContain("        __DIR__.'/src',\n");
});

it('replaces the skeleton phpstan config with the template and ships no baseline', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => ['phpstan']]);
    $processRunner = new FakeProcessRunner;
    $step = makeQualityToolsInstall($installerOption, $processRunner);

    touch($installerOption->targetPath.'/phpstan.neon');

    $step->execute();

    // Deleted outright, and no .bak left behind: on a fresh scaffold that file is the
    // skeleton's, and a backup of it would be litter in every generated application.
    expect(file_exists($installerOption->targetPath.'/phpstan.neon'))->toBeFalse()
        ->and(file_exists($installerOption->targetPath.'/phpstan.neon.bak'))->toBeFalse()
        ->and(file_exists($installerOption->targetPath.'/phpstan.neon.dist'))->toBeTrue()
        // The published stubs are repaired in AppFinalize instead, so a scaffold has
        // nothing to concede and starts with no baseline file at all.
        ->and(file_exists($installerOption->targetPath.'/phpstan-baseline.neon'))->toBeFalse();
});

// A replay reaches this step over a phpstan.neon that the user may well have written while
// narrowing the analysis to work out why the first run failed. It still has to go — it would
// shadow the published .dist — but silently deleting someone's file is not the way.
it('keeps a phpstan config written between runs when resuming', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => ['phpstan'], 'resume' => true]);
    $processRunner = new FakeProcessRunner;
    $step = makeQualityToolsInstall($installerOption, $processRunner);

    file_put_contents($installerOption->targetPath.'/phpstan.neon', "parameters:\n    level: 3\n");

    $step->execute();

    expect(file_exists($installerOption->targetPath.'/phpstan.neon'))->toBeFalse()
        ->and(file_get_contents($installerOption->targetPath.'/phpstan.neon.bak'))->toContain('level: 3')
        ->and(file_exists($installerOption->targetPath.'/phpstan.neon.dist'))->toBeTrue();
});

it('gitignores the ide-helper files only once across repeated runs', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => ['phpstan']]);
    $processRunner = new FakeProcessRunner;
    $step = makeQualityToolsInstall($installerOption, $processRunner);

    file_put_contents($installerOption->targetPath.'/.gitignore', "/vendor\n");

    $step->execute();
    $step->execute();

    $gitignore = (string) file_get_contents($installerOption->targetPath.'/.gitignore');

    expect(substr_count($gitignore, '_ide_helper.php'))->toBe(1)
        ->and(substr_count($gitignore, '_ide_helper_models.php'))->toBe(1)
        ->and(substr_count($gitignore, '.phpstorm.meta.php'))->toBe(1)
        ->and($gitignore)->toStartWith("/vendor\n");
});

it('registers the composer scripts for a full tool selection', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => ['pint', 'phpstan', 'rector']]);
    $processRunner = new FakeProcessRunner;

    makeQualityToolsInstall($installerOption, $processRunner)->execute();

    expect(qualityComposerScripts($installerOption))->toBe([
        'pint:dry' => 'vendor/bin/pint --test',
        'pint:fix' => 'vendor/bin/pint',
        'phpstan' => 'vendor/bin/phpstan analyse',
        'phpstan-clear' => 'vendor/bin/phpstan clear-result-cache',
        'ide-helper' => [
            '@php artisan ide-helper:generate',
            '@php artisan ide-helper:meta',
            '@php artisan ide-helper:models --nowrite',
        ],
        'rector:dry' => 'vendor/bin/rector --dry-run',
        'rector:fix' => 'vendor/bin/rector process',
        'quality' => ['@pint:dry', '@phpstan', '@rector:dry'],
    ]);
});

it('registers the ide-helper script only when phpstan is selected', function (): void {
    $installerOption = makeInstallerOption(['qualityTools' => ['pint', 'rector']]);
    $processRunner = new FakeProcessRunner;

    makeQualityToolsInstall($installerOption, $processRunner)->execute();

    expect(qualityComposerScripts($installerOption))->toBe([
        'pint:dry' => 'vendor/bin/pint --test',
        'pint:fix' => 'vendor/bin/pint',
        'rector:dry' => 'vendor/bin/rector --dry-run',
        'rector:fix' => 'vendor/bin/rector process',
        'quality' => ['@pint:dry', '@rector:dry'],
    ]);
});
