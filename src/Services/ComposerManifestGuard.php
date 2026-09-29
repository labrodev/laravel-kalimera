<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Closure;
use Kalimera\Contracts\ProcessRunner;

use function Laravel\Prompts\warning;

use Throwable;

/**
 * Composer invents a project when composer.json is not on disk. `require` writes a fresh
 * manifest holding nothing but the package being added, resolves the whole dependency
 * graph against it, prunes vendor/ down to that one package's dependencies and rewrites
 * composer.lock to match — reporting success at every step. What is left behind is an
 * application with no autoload rules, no dev dependencies and no ./vendor/bin/sail, so
 * the next command in the scaffold dies on "No such file or directory" with nothing in
 * its message to connect it back to the composer command that caused it.
 *
 * Only a missing manifest does this. An empty one is a parse error composer refuses to
 * work past, and a valid one it edits in place. So the defence is to put the manifest
 * back before composer starts rather than to repair the damage afterwards, and this
 * guard keeps a known-good copy for exactly that: it restores the manifest ahead of every
 * composer command and before every retry, repairs it again on the way out in case
 * composer replaced it mid-run, and re-resolves so vendor/ agrees with what it repaired.
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
    public function runCommand(array $command, ?string $cwd = null, ?float $timeout = null, int $attempts = 1, bool $replayTail = true): void
    {
        if (! in_array('composer', $command, true)) {
            $this->processRunner->runCommand(attempts: $attempts, command: $command, cwd: $cwd, replayTail: $replayTail, timeout: $timeout);

            return;
        }

        $snapshot = $this->isDryRun() ? null : $this->healthySnapshot();

        if ($snapshot !== null) {
            $this->remember($snapshot);
            $this->ensureManifest($snapshot);
        }

        $allowedAttempts = max(1, $attempts);

        for ($attempt = 1; $attempt <= $allowedAttempts; $attempt++) {
            try {
                // This guard runs its own retry loop, so the inner runner is always on
                // its single attempt and would replay a tail for every failure the loop
                // goes on to recover from. Only the attempt with nothing left after it
                // has a failure worth putting on the terminal.
                $this->processRunner->runCommand(
                    command: $attempt === 1 ? $command : $this->serialized($command),
                    cwd: $cwd,
                    replayTail: $replayTail && $attempt === $allowedAttempts,
                    timeout: $timeout,
                );

                if ($this->restoreManifest($snapshot)) {
                    $this->resyncVendorDirectory();
                }

                return;
            } catch (Throwable $throwable) {
                $restored = $this->restoreManifest($snapshot);

                if ($attempt >= $allowedAttempts) {
                    throw $throwable;
                }

                $this->recoverVendorDirectory(restored: $restored);
                $this->ensureManifest($snapshot);
            }
        }
    }

    /**
     * Prefer the manifest on disk while it still looks like the application's, and fall
     * back to the copy kept from the last composer command that ran against a healthy one.
     * The fallback is what covers a manifest that went missing between two steps: there is
     * nothing left on disk to snapshot by then, and without it composer would be handed
     * the empty directory it treats as an invitation to start a new project.
     */
    private function healthySnapshot(): ?string
    {
        $current = $this->readManifest();

        if ($current !== null && isset($this->decode($current)['autoload'])) {
            return $current;
        }

        return $this->rememberedManifest();
    }

    /**
     * Put the manifest back before composer runs. Repairing it afterwards cannot undo a
     * resolution that already happened against the wrong file — the lock is rewritten and
     * vendor/ is pruned by then — so this is the check that actually prevents the damage,
     * and the one after the command only catches a manifest composer replaced mid-run.
     */
    private function ensureManifest(?string $snapshot): void
    {
        if ($snapshot === null || $this->isDryRun()) {
            return;
        }

        $current = $this->readManifest();

        if ($current !== null && isset($this->decode($current)['autoload'])) {
            return;
        }

        $this->preserveDamaged($current);

        (new FileWriter)(contents: $snapshot, path: $this->manifestPath());

        sleep($this->settleDelaySeconds);

        warning(sprintf(
            'composer.json was %s before this command — restored it, and kept what was there as %s.',
            $current === null ? 'gone' : 'not the application manifest any more',
            $this->damagedPath(),
        ));
    }

    /**
     * Nothing in kalimera removes composer.json, so a manifest that is missing or no longer
     * the application's arrived that way from outside this process. Keep whatever was found
     * instead of overwriting it silently: it is the only evidence of what did it.
     */
    private function preserveDamaged(?string $contents): void
    {
        try {
            (new FileWriter)(contents: $contents ?? '', path: $this->damagedPath());
        } catch (Throwable) {
            // Losing the evidence is not worth failing the scaffold over.
        }
    }

    /**
     * Kept in the run state file inside the application rather than anywhere global: it
     * describes this application's manifest for this run, so it has to go when the run
     * completes. A copy that outlived its application would be restored into the next one
     * scaffolded at the same path.
     */
    private function remember(string $contents): void
    {
        try {
            new RunStateFile($this->targetPath)->saveComposerSnapshot($contents);
        } catch (Throwable) {
            // The copy is a safety net; the run is no worse off than before without it.
        }
    }

    private function rememberedManifest(): ?string
    {
        return new RunStateFile($this->targetPath)->composerSnapshot();
    }

    /**
     * Evidence rather than state, so it lives outside the application — the run may yet
     * succeed and clear its state, and this is the one file that says what went wrong.
     */
    private function damagedPath(): string
    {
        return sys_get_temp_dir().'/kalimera-manifest-'.md5($this->targetPath).'.damaged.json';
    }

    /**
     * A composer command that succeeded against a gutted manifest resolved and installed
     * against it too, so the lock file and vendor/ now hold exactly what that manifest
     * asked for — which is to say the application's dev dependencies are gone. Restoring
     * composer.json cannot bring them back on its own, and `composer install` would only
     * reinstate the damage, the lock file having been written from the broken manifest.
     * Re-resolving is the one operation that reads the manifest we just repaired.
     *
     * Left out, the run continues against a vendor/ that no longer has ./vendor/bin/sail
     * in it, and the next step dies on "No such file or directory" — a failure with
     * nothing in its message to connect it back to the composer command that caused it.
     */
    private function resyncVendorDirectory(): void
    {
        warning('Re-resolving dependencies so vendor/ matches the restored composer.json.');

        $this->recover($this->sailCommandBuilder->composer('update', '--no-interaction'));
    }

    /**
     * Downloads that arrive truncated are still written to composer's cache, so every
     * later attempt replays the same broken archive. Dropping the cache and reinstalling
     * — one download at a time — puts vendor/ back in shape. Install from the lock file
     * unless the manifest had to be restored, in which case the lock describes the broken
     * manifest and only a re-resolve can agree with the one on disk.
     */
    private function recoverVendorDirectory(bool $restored): void
    {
        $this->recover($this->sailCommandBuilder->composer('clear-cache'));
        $this->recover($this->sailCommandBuilder->composer($restored ? 'update' : 'install', '--no-interaction'));
    }

    /**
     * @param  list<string>  $command
     */
    private function recover(array $command): void
    {
        try {
            // A recovery that does not work changes nothing about what happens next — the
            // original command is retried either way — so its output is not the failure
            // the reader needs to see.
            $this->processRunner->runCommand(
                command: $this->serialized($command),
                cwd: $this->sailCommandBuilder->path(),
                replayTail: false,
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
    public function probe(array $command, ?string $cwd = null): bool
    {
        return $this->processRunner->probe(command: $command, cwd: $cwd);
    }

    /**
     * @param  list<string>  $command
     */
    public function attemptQuietly(array $command, ?string $cwd = null): bool
    {
        return $this->processRunner->attemptQuietly(command: $command, cwd: $cwd);
    }

    public function applyFileChange(string $description, Closure $action): void
    {
        $this->processRunner->applyFileChange(action: $action, description: $description);
    }

    private function restoreManifest(?string $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }

        $healthy = $this->decode($snapshot);

        if (! isset($healthy['autoload'])) {
            return false;
        }

        $current = $this->decode($this->readManifest() ?? '');

        if (isset($current['autoload'])) {
            return false;
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

        return true;
    }

    private function readManifest(): ?string
    {
        return $this->read($this->manifestPath());
    }

    private function read(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false || $contents === '' ? null : $contents;
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
