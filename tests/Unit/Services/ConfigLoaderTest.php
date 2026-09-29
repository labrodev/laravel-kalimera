<?php

declare(strict_types=1);

use Kalimera\Exceptions\InvalidConfigException;
use Kalimera\Payloads\AdditionalPackage;
use Kalimera\Payloads\InstallerConfig;
use Kalimera\Services\ConfigLoader;

/**
 * @param  array<string, mixed>  $config
 */
function writeConfig(array $config): string
{
    $path = tempDir().'/kalimera.config.json';

    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
}

it('loads the built-in setup from the packaged schema defaults', function (): void {
    /** @var array{properties: array{preselected: array{default: array<string, mixed>}, additionalPackages: array{default: list<array<string, mixed>>}}} $schema */
    $schema = json_decode(
        associative: true,
        flags: JSON_THROW_ON_ERROR,
        json: (string) file_get_contents(__DIR__.'/../../../schema/kalimera.config.schema.json'),
    );

    $installerConfig = (new ConfigLoader)(configPath: null);

    expect($installerConfig->starterKit)->toBe($schema['properties']['preselected']['default']['starterKit'])
        ->and($installerConfig->aroundPackages)->toBe($schema['properties']['preselected']['default']['aroundPackages'])
        ->and($installerConfig->boostAgents)->toBe($schema['properties']['preselected']['default']['boostAgents'])
        ->and(array_map(
            fn (AdditionalPackage $additionalPackage): string => $additionalPackage->package,
            $installerConfig->additionalPackages,
        ))->toBe(array_column($schema['properties']['additionalPackages']['default'], 'package'));
});

it('overrides the preselected answers from the config file', function (): void {
    $path = writeConfig([
        'preselected' => [
            'starterKit' => 'vue',
            'sailServices' => ['mysql', 'redis'],
            'phpConstraint' => '~8.4.0',
            'installPostmark' => true,
            'coreNamespace' => null,
            'boostSkillRepos' => ['labrodev/skills'],
        ],
    ]);

    $installerConfig = (new ConfigLoader)(configPath: $path);

    expect($installerConfig->starterKit)->toBe('vue')
        ->and($installerConfig->sailServices)->toBe(['mysql', 'redis'])
        ->and($installerConfig->phpConstraint)->toBe('~8.4.0')
        ->and($installerConfig->installPostmark)->toBeTrue()
        ->and($installerConfig->coreNamespace)->toBeNull()
        ->and($installerConfig->boostSkillRepos)->toBe(['labrodev/skills'])
        ->and($installerConfig->qualityTools)->toBe(['pint', 'phpstan', 'rector', 'vet'])
        ->and($installerConfig->additionalPackages)->toEqual((new ConfigLoader)(configPath: null)->additionalPackages);
});

it('replaces the additional-packages catalog from the config file', function (): void {
    $path = writeConfig([
        '$schema' => 'https://example.com/schema.json',
        'additionalPackages' => [
            ['package' => 'spatie/laravel-medialibrary', 'label' => 'medialibrary — file attachments', 'preselected' => true, 'publishProviders' => ['Spatie\\MediaLibrary\\MediaLibraryServiceProvider']],
            ['package' => 'barryvdh/laravel-debugbar', 'dev' => true],
        ],
    ]);

    $installerConfig = (new ConfigLoader)(configPath: $path);

    expect($installerConfig->additionalPackageOptions())->toBe([
        'spatie/laravel-medialibrary' => 'medialibrary — file attachments',
        'barryvdh/laravel-debugbar' => 'barryvdh/laravel-debugbar',
    ])
        ->and($installerConfig->preselectedAdditionalPackages())->toBe(['spatie/laravel-medialibrary'])
        ->and($installerConfig->findAdditionalPackage('barryvdh/laravel-debugbar')?->dev)->toBeTrue()
        ->and($installerConfig->findAdditionalPackage('spatie/laravel-medialibrary')?->publishProviders)
        ->toBe(['Spatie\\MediaLibrary\\MediaLibraryServiceProvider']);
});

it('trims a surrounding backslash from the core namespace', function (): void {
    $path = writeConfig(['preselected' => ['coreNamespace' => 'Acme\\Core\\']]);

    expect((new ConfigLoader)(configPath: $path)->coreNamespace)->toBe('Acme\\Core');
});

it('rejects a bare config flag', function (): void {
    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: ''))
        ->toThrow(InvalidConfigException::class, '--config flag requires a path');
});

it('rejects a missing explicit config file', function (): void {
    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: tempDir().'/missing.json'))
        ->toThrow(InvalidConfigException::class, 'does not exist');
});

it('rejects invalid json', function (): void {
    $path = tempDir().'/kalimera.config.json';
    file_put_contents($path, '{not json');

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'not valid JSON');
});

it('rejects an unknown top-level key', function (): void {
    $path = writeConfig(['spatiePackages' => []]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'unknown key "spatiePackages"');
});

it('points a legacy "defaults" key at the new name', function (): void {
    $path = writeConfig(['defaults' => ['starterKit' => 'vue']]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, '"defaults" was renamed to "preselected"');
});

it('rejects an unknown preselected key', function (): void {
    $path = writeConfig(['preselected' => ['starterkit' => 'vue']]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'unknown key "starterkit"');
});

it('rejects a starter kit outside the known options', function (): void {
    $path = writeConfig(['preselected' => ['starterKit' => 'angular']]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'must be one of react, vue, livewire, svelte, none');
});

it('rejects a sail service outside the known options', function (): void {
    $path = writeConfig(['preselected' => ['sailServices' => ['pgsql', 'mongodb']]]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'got "mongodb"');
});

it('rejects a php constraint without a version', function (): void {
    $path = writeConfig(['preselected' => ['phpConstraint' => 'latest']]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'must contain a version like 8.5');
});

it('rejects a catalog entry without a package name', function (): void {
    $path = writeConfig(['additionalPackages' => [['label' => 'mystery']]]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'needs a non-empty "package"');
});

it('rejects a catalog entry listed twice', function (): void {
    $path = writeConfig(['additionalPackages' => [
        ['package' => 'vendor/package'],
        ['package' => 'vendor/package'],
    ]]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'lists vendor/package twice');
});

it('rejects an invalid core namespace', function (): void {
    $path = writeConfig(['preselected' => ['coreNamespace' => 'core-stuff']]);

    expect(fn (): InstallerConfig => (new ConfigLoader)(configPath: $path))
        ->toThrow(InvalidConfigException::class, 'StudlyCase');
});
