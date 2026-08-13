<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Services\ComposerManifestGuard;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function healthyManifest(): string
{
    return json_encode([
        'name' => 'laravel/react-starter-kit',
        'require' => ['php' => '^8.5', 'laravel/framework' => '^13.17'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'scripts' => ['test' => ['@php artisan test']],
    ], JSON_PRETTY_PRINT)."\n";
}

function makeComposerManifestGuard(string $targetPath, FakeProcessRunner $processRunner): ComposerManifestGuard
{
    return new ComposerManifestGuard(
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $targetPath),
        settleDelaySeconds: 0,
        targetPath: $targetPath,
    );
}

it('passes non-composer commands straight through', function (): void {
    $targetPath = tempDir();
    $processRunner = new FakeProcessRunner;

    makeComposerManifestGuard($targetPath, $processRunner)
        ->runCommand(command: ['./vendor/bin/sail', 'artisan', 'migrate'], cwd: $targetPath);

    expect($processRunner->commandLines())->toBe(['./vendor/bin/sail artisan migrate']);
});

it('leaves a healthy manifest untouched', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/composer.json', healthyManifest());
    $processRunner = new FakeProcessRunner;

    makeComposerManifestGuard($targetPath, $processRunner)
        ->runCommand(command: ['./vendor/bin/sail', 'composer', 'require', 'laravel/horizon'], cwd: $targetPath);

    expect(file_get_contents($targetPath.'/composer.json'))->toBe(healthyManifest())
        ->and($processRunner->commandLines())->toBe(['./vendor/bin/sail composer require laravel/horizon']);
});

it('restores the manifest when composer gutted it during a successful command', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/composer.json', healthyManifest());
    $processRunner = new FakeProcessRunner;
    $processRunner->onCommand('composer require', function () use ($targetPath): void {
        file_put_contents($targetPath.'/composer.json', '{"require": {"laravel/horizon": "^5.48"}}');
    });

    makeComposerManifestGuard($targetPath, $processRunner)
        ->runCommand(command: ['./vendor/bin/sail', 'composer', 'require', 'laravel/horizon'], cwd: $targetPath);

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    expect($manifest['autoload']['psr-4']['App\\'])->toBe('app/')
        ->and($manifest['scripts'])->not->toBeEmpty()
        ->and($manifest['require'])->toBe([
            'php' => '^8.5',
            'laravel/framework' => '^13.17',
            'laravel/horizon' => '^5.48',
        ]);
});

it('restores the manifest and reinstalls from the lock file before retrying a failed command', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/composer.json', healthyManifest());
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'composer require', times: 1);
    $processRunner->onCommand('composer require', function () use ($targetPath): void {
        file_put_contents($targetPath.'/composer.json', '{"require": {"laravel/horizon": "^5.48"}}');
    });

    makeComposerManifestGuard($targetPath, $processRunner)
        ->runCommand(attempts: 3, command: ['./vendor/bin/sail', 'composer', 'require', 'laravel/horizon'], cwd: $targetPath);

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/horizon',
        'docker compose exec -T -u sail -e COMPOSER_MAX_PARALLEL_HTTP=1 laravel.test composer clear-cache',
        'docker compose exec -T -u sail -e COMPOSER_MAX_PARALLEL_HTTP=1 laravel.test composer install --no-interaction',
        'docker compose exec -T -u sail -e COMPOSER_MAX_PARALLEL_HTTP=1 laravel.test composer require laravel/horizon',
    ]);

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    expect($manifest['autoload'])->not->toBeEmpty();
});

it('restores the manifest before giving up on a command that keeps failing', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/composer.json', healthyManifest());
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('composer require');
    $processRunner->onCommand('composer require', function () use ($targetPath): void {
        file_put_contents($targetPath.'/composer.json', '{"require": {}}');
    });

    $composerManifestGuard = makeComposerManifestGuard($targetPath, $processRunner);

    expect(fn () => $composerManifestGuard->runCommand(command: ['./vendor/bin/sail', 'composer', 'require', 'laravel/horizon'], cwd: $targetPath))
        ->toThrow(CommandFailedException::class);

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    expect($manifest['autoload'])->not->toBeEmpty();
});

it('delegates the remaining runner behaviour', function (): void {
    $targetPath = tempDir();
    $processRunner = new FakeProcessRunner(dryRun: true);
    $composerManifestGuard = makeComposerManifestGuard($targetPath, $processRunner);

    $composerManifestGuard->applyFileChange(description: 'do something', action: fn () => null);
    $quietResult = $composerManifestGuard->runCommandQuietly(command: ['docker', 'info']);

    expect($composerManifestGuard->isDryRun())->toBeTrue()
        ->and($quietResult)->toBeTrue()
        ->and($processRunner->fileActions)->toBe(['do something']);
});
