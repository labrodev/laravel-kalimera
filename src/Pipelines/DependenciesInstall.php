<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\warning;

use Throwable;

/**
 * The half of the install the host left out, run where the application's PHP lives.
 * vendor/ is already complete (PackagesRequire), so `composer install` downloads nothing:
 * it checks the extension requirements the host skipped, runs the plugins and regenerates
 * the autoloader — whose post-autoload-dump script is `package:discover`, the first code
 * of any installed package to execute — and the public assets are published after it.
 */
readonly class DependenciesInstall implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Installing dependencies in the container';
    }

    public function execute(): void
    {
        $this->recordTrustFile();

        $this->processRunner->runCommand(
            command: $this->sailCommandBuilder->composer('install', '--no-interaction'),
            cwd: $this->sailCommandBuilder->path(),
        );

        // The skeleton's post-update-cmd, which neither half of this install triggers: the
        // host required with --no-scripts and `install` is not an update. Packages that ship
        // public assets (Telescope, Livewire) publish them under this tag, and a stack with
        // none answers "no publishable resources" and exits 0.
        $this->processRunner->runCommand(
            command: $this->sailCommandBuilder->artisan('vendor:publish', '--tag=laravel-assets', '--force', '--no-interaction'),
            cwd: $this->sailCommandBuilder->path(),
        );
    }

    /**
     * Before `composer install`, because vet's plugin audits every install against this
     * file. vendor/ already holds everything the scaffold asked for, so `--init` records the
     * finished tree as the baseline and every change after it has to be read. No
     * release-age floor is set: one holds back fresh releases even when trusted, so a new
     * Laravel would arrive failing its own audit (see TROUBLESHOOTING.md).
     *
     * Warns rather than fails: the application is sound without it, and vet.json can be
     * recorded by hand.
     */
    private function recordTrustFile(): void
    {
        if (! $this->installerOption->installsVet()) {
            return;
        }

        try {
            $this->processRunner->runCommand(
                command: $this->sailCommandBuilder->command('php', 'vendor/bin/vet', '--init', '--no-interaction'),
                cwd: $this->sailCommandBuilder->path(),
            );
        } catch (Throwable) {
            warning('Vet could not record the trust file — run `sail php vendor/bin/vet --init` in a terminal to finish it.');
        }
    }
}
