<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Payloads\MigrationFailure;
use Kalimera\Services\InstallerOptionStore;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\warning;

use Throwable;

readonly class AppFinalize implements Pipeline
{
    /**
     * Waiting out a database that has not finished booting is a different problem from a
     * flaky download, so it gets its own budget rather than ProcessRunner::NETWORK_ATTEMPTS.
     */
    private const int MIGRATE_ATTEMPTS = 3;

    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
        private int $retryDelaySeconds = 5,
    ) {}

    public function label(): string
    {
        return 'Finalizing the application';
    }

    public function execute(): void
    {
        $this->migrate();

        $this->processRunner->runCommand(attempts: ProcessRunner::NETWORK_ATTEMPTS, command: $this->sailCommandBuilder->npm('install'), cwd: $this->sailCommandBuilder->path(), timeout: null);

        if ($this->installerOption->wantsQualityTool('phpstan')) {
            $this->softRun(
                command: $this->sailCommandBuilder->composer('ide-helper'),
                failure: 'ide-helper generation failed — run `sail composer ide-helper` manually.',
            );
        }

        $this->applyQualityBaseline();
        $this->commit();
    }

    private function migrate(): void
    {
        try {
            $this->migrateWithRetries();

            return;
        } catch (Throwable $throwable) {
            if ($this->processRunner->isDryRun() || ! $this->installerOption->usesDatabaseService()) {
                throw $throwable;
            }

            // An interrupted scaffold leaves schema behind that blocks migrate, and
            // orphaned sequences survive even `db:wipe`. The application has never run at
            // this point, so recreating the volume is both safe and engine-agnostic.
            $reason = match ($this->classify($throwable)) {
                MigrationFailure::SchemaConflict => 'Migrations hit schema left over from an earlier run — recreating the database volume.',
                MigrationFailure::Unavailable => 'The database never became reachable — recreating its volume and migrating again.',
                // Recreating answers one question: is the existing data in the way? A server
                // that answered and refused on its own terms — a migration that will not
                // parse, a constraint the schema cannot satisfy — refuses a fresh database
                // identically, so destroying it buys nothing and hides the real error behind
                // a second copy of itself.
                MigrationFailure::Rejected => null,
            };

            if ($reason === null) {
                throw $throwable;
            }

            warning($reason);
        }

        $this->processRunner->runCommand(
            command: $this->sailCommandBuilder->command('down', '-v'),
            cwd: $this->sailCommandBuilder->path(),
        );

        $this->processRunner->runCommand(
            command: $this->sailCommandBuilder->command('up', '-d', '--wait'),
            cwd: $this->sailCommandBuilder->path(),
        );

        $this->migrateWithRetries();
    }

    private function migrateWithRetries(): void
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                $this->processRunner->runCommand(
                    command: $this->sailCommandBuilder->artisan('migrate', '--no-interaction'),
                    cwd: $this->sailCommandBuilder->path(),
                );

                return;
            } catch (Throwable $throwable) {
                // Waiting cannot clear schema that is already there: every retry replays
                // the same duplicate-relation error and buries the real cause in noise.
                if (! $this->classify($throwable)->shouldRetry()) {
                    throw $throwable;
                }

                if ($attempts >= self::MIGRATE_ATTEMPTS || $this->processRunner->isDryRun()) {
                    throw $throwable;
                }

                warning(sprintf('Database is not ready yet — retrying in %d seconds.', $this->retryDelaySeconds));
                sleep($this->retryDelaySeconds);
            }
        }
    }

    private function classify(Throwable $throwable): MigrationFailure
    {
        return $throwable instanceof CommandFailedException
            ? MigrationFailure::fromOutput($throwable->output)
            : MigrationFailure::Unavailable;
    }

    private function applyQualityBaseline(): void
    {
        if ($this->installerOption->wantsQualityTool('rector')) {
            // Rector needs a second pass to converge (a first-pass rewrite can enable further rules).
            $this->softRun(command: $this->sailCommandBuilder->composer('rector:fix'), failure: 'Rector could not refactor the fresh codebase.');
            $this->softRun(command: $this->sailCommandBuilder->composer('rector:fix'), failure: 'Rector could not refactor the fresh codebase.');
        }

        if ($this->installerOption->wantsQualityTool('pint')) {
            $this->softRun(command: $this->sailCommandBuilder->composer('pint:fix'), failure: 'Pint could not format the fresh codebase.');
        }

        if ($this->installerOption->wantsQualityTool('phpstan')) {
            try {
                $this->processRunner->runCommand(command: $this->sailCommandBuilder->composer('phpstan'), cwd: $this->sailCommandBuilder->path());
            } catch (Throwable) {
                warning('PHPStan found issues in the fresh skeleton — generating a baseline so you start green.');

                $this->softRun(
                    command: $this->sailCommandBuilder->command(
                        'php',
                        'vendor/bin/phpstan',
                        'analyse',
                        '--generate-baseline=phpstan-baseline.neon',
                        '--allow-empty-baseline',
                        '--memory-limit=1G',
                    ),
                    failure: 'PHPStan baseline generation failed — run it manually.',
                );
            }
        }

        if ($this->installerOption->qualityTools === []) {
            return;
        }

        $this->softRun(
            command: $this->sailCommandBuilder->composer('quality'),
            failure: 'The `composer quality` check is not green yet — review it manually.',
        );
    }

    private function commit(): void
    {
        $this->processRunner->applyFileChange(
            action: fn () => (new InstallerOptionStore)->forget($this->installerOption->targetPath),
            description: 'remove .kalimera.json — the scaffold completed, --continue is no longer needed',
        );

        if (! $this->processRunner->isDryRun() && ! is_dir($this->installerOption->targetPath.'/.git')) {
            $this->softRun(command: ['git', 'init', '-b', 'main'], failure: 'git init failed.');
        }

        $this->softRun(command: ['git', 'add', '-A'], failure: 'git add failed.');
        $this->softRun(
            command: ['git', 'commit', '-m', 'chore: scaffold application with kalimera'],
            failure: 'git commit failed — commit manually when ready.',
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function softRun(array $command, string $failure): void
    {
        try {
            $this->processRunner->runCommand(command: $command, cwd: $this->sailCommandBuilder->path());
        } catch (Throwable) {
            warning($failure);
        }
    }
}
