<?php

declare(strict_types=1);

use Kalimera\Exceptions\ComposerFileUnreadableException;
use Kalimera\Services\ComposerFileEditor;

/**
 * @param  array<string, mixed>  $contents
 */
function composerFixture(array $contents): string
{
    $path = tempDir().'/composer.json';

    file_put_contents($path, json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return $path;
}

/**
 * @return array<string, mixed>
 */
function decodedComposer(string $path): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(associative: true, flags: JSON_THROW_ON_ERROR, json: (string) file_get_contents($path));

    return $decoded;
}

it('puts php first in require while preserving the other packages and their order', function (): void {
    $path = composerFixture([
        'require' => [
            'laravel/framework' => '^12.0',
            'php' => '^8.2',
            'guzzlehttp/guzzle' => '^7.8',
        ],
    ]);

    $composer = new ComposerFileEditor($path);
    $composer->setPhpConstraint('^8.5');
    $composer->save();

    expect(decodedComposer($path))->toBe([
        'require' => [
            'php' => '^8.5',
            'laravel/framework' => '^12.0',
            'guzzlehttp/guzzle' => '^7.8',
        ],
    ]);
});

it('adds string and array scripts', function (): void {
    $path = composerFixture([]);

    $composer = new ComposerFileEditor($path);
    $composer->addScript(name: 'phpstan', script: 'vendor/bin/phpstan analyse');
    $composer->addScript(name: 'quality', script: ['@phpstan', '@test']);
    $composer->save();

    expect(decodedComposer($path))->toBe([
        'scripts' => [
            'phpstan' => 'vendor/bin/phpstan analyse',
            'quality' => ['@phpstan', '@test'],
        ],
    ]);
});

it('appends script entries without duplicating existing ones', function (): void {
    $path = composerFixture([
        'scripts' => [
            'post-update-cmd' => ['@php artisan vendor:publish'],
        ],
    ]);

    $composer = new ComposerFileEditor($path);
    $composer->appendScript(entries: ['@php artisan vendor:publish', '@php artisan ide-helper:generate'], name: 'post-update-cmd');
    $composer->save();

    expect(decodedComposer($path))->toBe([
        'scripts' => [
            'post-update-cmd' => ['@php artisan vendor:publish', '@php artisan ide-helper:generate'],
        ],
    ]);
});

it('converts an existing string script to a list when appending', function (): void {
    $path = composerFixture([
        'scripts' => [
            'quality' => '@pint:dry',
        ],
    ]);

    $composer = new ComposerFileEditor($path);
    $composer->appendScript(entries: ['@phpstan'], name: 'quality');
    $composer->save();

    expect(decodedComposer($path))->toBe([
        'scripts' => [
            'quality' => ['@pint:dry', '@phpstan'],
        ],
    ]);
});

it('maps a psr-4 namespace', function (): void {
    $path = composerFixture([
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
            ],
        ],
    ]);

    $composer = new ComposerFileEditor($path);
    $composer->addPsr4(namespace: 'Core\\', path: 'src/');
    $composer->save();

    expect(decodedComposer($path))->toBe([
        'autoload' => [
            'psr-4' => [
                'App\\' => 'app/',
                'Core\\' => 'src/',
            ],
        ],
    ]);
});

it('saves pretty json with four-space indentation, unescaped slashes and a trailing newline', function (): void {
    $path = composerFixture([
        'require' => [
            'laravel/framework' => '^12.0',
        ],
    ]);

    new ComposerFileEditor($path)->save();

    $raw = (string) file_get_contents($path);

    expect($raw)->toContain('    "require": {')
        ->and($raw)->toContain('"laravel/framework": "^12.0"')
        ->and($raw)->not->toContain('\/')
        ->and($raw)->toEndWith("}\n");
});

it('throws when the composer file cannot be read', function (): void {
    $path = tempDir().'/missing/composer.json';

    set_error_handler(fn (): bool => true);

    try {
        expect(fn (): ComposerFileEditor => new ComposerFileEditor($path))->toThrow(ComposerFileUnreadableException::class);
    } finally {
        restore_error_handler();
    }
});

it('allows a composer plugin beside the ones already allowed', function (): void {
    $path = composerFixture(['config' => ['allow-plugins' => ['pestphp/pest-plugin' => true]]]);

    $composer = new ComposerFileEditor($path);
    $composer->allowPlugin('laravel/vet');
    $composer->save();

    expect(decodedComposer($path))->toBe([
        'config' => ['allow-plugins' => ['pestphp/pest-plugin' => true, 'laravel/vet' => true]],
    ]);
});

it('creates the allow-plugins list when the manifest has no config section', function (): void {
    $path = composerFixture(['require' => ['php' => '^8.4']]);

    $composer = new ComposerFileEditor($path);
    $composer->allowPlugin('laravel/vet');
    $composer->save();

    expect(decodedComposer($path))->toBe([
        'require' => ['php' => '^8.4'],
        'config' => ['allow-plugins' => ['laravel/vet' => true]],
    ]);
});
