<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Closure;
use Kalimera\Contracts\ProcessRunner;

use function Laravel\Prompts\warning;

use Throwable;

/**
 * Composer rewrites composer.json in place and, when an install fails midway, restores it
 * from its own backup. On a bind-mounted project that backup can come back empty, leaving
 * a manifest without the application's autoload rules — every later artisan command then
 * dies with "Unable to detect application namespace", and a plain retry of the composer
 * command happily succeeds against the broken file. This guard snapshots the manifest
 * around every composer command, restores it when composer loses its sections, and
 * reinstalls from the lock file so vendor/ is whole again before the command is retried.
 */
readonly class ComposerManifestGuard implements ProcessRunner
{
    public function __construct(
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
        private string $targetPath,
        private int $settleDelaySeconds = 1,
    ) {}

    public function isDryRun(): bool
    {
        return $this->processRunner->isDryRun();
    }

    /**
     * @param  list<string>  $command
     */
    public function runCommand(array $command, ?string $cwd = null, ?float $timeout = null, int $attempts = 1): void
    {
        if (! in_array('composer', $command, true)) {
            $this->processRunner->runCommand(attempts: $attempts, command: $command, cwd: $cwd, timeout: $timeout);

            return;
        }

        $snapshot = $this->readManifest();
        $allowedAttempts = max(1, $attempts);

        for ($attempt = 1; $attempt <= $allowedAttempts; $attempt++) {
            try {
                $this->processRunner->runCommand(
                    command: $attempt === 1 ? $command : $this->serialized($command),
                    cwd: $cwd,
                    timeout: $timeout,
                );
                $this->restoreManifest($snapshot);

                return;
            } catch (Throwable $throwable) {
                $this->restoreManifest($snapshot);

                if ($attempt >= $allowedAttempts) {
                    throw $throwable;
                }

                $this->recoverVendorDirectory();
            }
        }
    }

    /**
     * Downloads that arrive truncated are still written to composer's cache, so every
     * later attempt replays the same broken archive. Dropping the cache and reinstalling
     * from the lock file — one download at a time — puts vendor/ back in shape.
     */
    private function recoverVendorDirectory(): void
    {
        $this->recover($this->sailCommandBuilder->composer('clear-cache'));
        $this->recover($this->sailCommandBuilder->composer('install', '--no-interaction'));
    }

    /**
     * @param  list<string>  $command
     */
    private function recover(array $command): void
    {
        try {
            $this->processRunner->runCommand(
                command: $this->serialized($command),
                cwd: $this->sailCommandBuilder->path(),
            );
        } catch (Throwable) {
            warning('Recovering vendor/ failed — retrying the original command anyway.');
        }
    }

    /**
     * Re-issue a `sail composer` command straight through docker so composer downloads
     * one package at a time: parallel downloads are what tends to arrive corrupted on a
     * bind mount, and Sail offers no way to pass the environment variable through.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function serialized(array $command): array
    {
        $position = array_search('composer', $command, true);

        if ($position === false || ! str_ends_with($command[0], 'sail')) {
            return $command;
        }

        return [
            'docker', 'compose', 'exec', '-T', '-u', 'sail',
            '-e', 'COMPOSER_MAX_PARALLEL_HTTP=1',
            'laravel.test',
            ...array_slice($command, $position),
        ];
    }

    /**
     * @param  list<string>  $command
     */
    public function runCommandQuietly(array $command, ?string $cwd = null): bool
    {
        return $this->processRunner->runCommandQuietly(command: $command, cwd: $cwd);
    }

    public function applyFileChange(string $description, Closure $action): void
    {
        $this->processRunner->applyFileChange(action: $action, description: $description);
    }

    private function restoreManifest(?string $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        $healthy = $this->decode($snapshot);

        if (! isset($healthy['autoload'])) {
            return;
        }

        $current = $this->decode($this->readManifest() ?? '');

        if (isset($current['autoload'])) {
            return;
        }

        foreach (['require', 'require-dev'] as $section) {
            $requirements = array_merge($this->requirements($healthy, $section), $this->requirements($current, $section));

            if ($requirements !== []) {
                $healthy[$section] = $requirements;
            }
        }

        (new FileWriter)(
            contents: json_encode($healthy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
            path: $this->manifestPath(),
        );

        sleep($this->settleDelaySeconds);

        warning('composer.json lost its application sections — restored them from the snapshot taken before this command.');
    }

    private function readManifest(): ?string
    {
        $contents = @file_get_contents($this->manifestPath());

        return $contents === false ? null : $contents;
    }

    private function manifestPath(): string
    {
        return $this->targetPath.'/composer.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $contents): array
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private function requirements(array $manifest, string $section): array
    {
        $requirements = $manifest[$section] ?? [];

        if (! is_array($requirements)) {
            return [];
        }

        $result = [];

        foreach ($requirements as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $result[$package] = $constraint;
            }
        }

        return $result;
    }
}
