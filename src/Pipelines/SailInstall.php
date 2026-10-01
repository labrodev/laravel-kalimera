<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\EnvFileWriter;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

readonly class SailInstall implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
    ) {}

    public function label(): string
    {
        return 'Installing Laravel Sail';
    }

    public function execute(): void
    {
        // On --continue, sail:install already ran — and host artisan may be unusable anyway
        // once vendor/ was rebuilt in-container under a PHP constraint newer than the host.
        if ($this->composeFileExists()) {
            info('Sail is already installed — keeping the existing services. Delete the compose file and re-run to reselect them.');

            return;
        }

        if (! $this->skeletonShipsSail()) {
            $this->processRunner->runCommand(
                command: ['composer', 'require', 'laravel/sail', '--dev', '--no-interaction'],
                cwd: $this->installerOption->targetPath,
            );
        }

        $this->processRunner->runCommand(
            // sail:install pulls every service image and builds the application image
            // whenever Docker answers. Both are premature here: the pull re-checks the
            // registry for images already on disk — a Docker Desktop whose pull path hangs
            // stalls the scaffold right here with nothing to say why — and the build is
            // repeated by `sail up` anyway. Pointing DOCKER_HOST at nothing makes its
            // `docker info` fail, so it only writes the compose file and .env; `sail up`
            // builds the image and pulls what is missing later.
            command: [
                'env',
                'DOCKER_HOST=unix:///nonexistent/kalimera.sock',
                'php',
                'artisan',
                'sail:install',
                // `none` is Sail's own keyword for "the application container only".
                '--with='.($this->installerOption->sailServices === [] ? 'none' : implode(',', $this->installerOption->sailServices)),
                ...$this->runtimeOption(),
                '--no-interaction',
            ],
            cwd: $this->installerOption->targetPath,
        );

        if ($this->installerOption->usesDatabaseService()) {
            $this->processRunner->applyFileChange(
                action: function (): void {
                    @unlink($this->installerOption->targetPath.'/database/database.sqlite');
                },
                description: 'remove the sqlite database left over from `laravel new`',
            );

            $this->processRunner->applyFileChange(
                action: fn () => $this->syncDatabaseBlockToEnvExample(),
                description: 'sync the DB_* block from .env to .env.example',
            );
        }
    }

    /**
     * Sail's own `--php` writes the runtime into both places compose.yaml names it — the
     * build context and the image tag. It substitutes whatever it is given without checking,
     * though, and a version Sail ships no runtime for would only fail at `sail up`, as a
     * build context that does not exist. So the option is passed only when the runtime is
     * there; otherwise Sail keeps its default and the warning says why.
     *
     * After the `composer require` above, so a skeleton without Sail has its runtimes on
     * disk by now. A dry run has no vendor/ to look in and assumes the runtime exists.
     *
     * @return list<string>
     */
    private function runtimeOption(): array
    {
        $version = $this->installerOption->phpMinorVersion();

        if ($this->processRunner->isDryRun() || is_dir($this->installerOption->targetPath.'/vendor/laravel/sail/runtimes/'.$version)) {
            return ['--php='.$version];
        }

        warning(sprintf('Sail does not ship a PHP %s runtime — keeping its default runtime.', $version));

        return [];
    }

    private function syncDatabaseBlockToEnvExample(): void
    {
        $environment = file_get_contents($this->installerOption->targetPath.'/.env');

        if ($environment === false) {
            return;
        }

        preg_match_all(matches: $matches, pattern: '/^(DB_[A-Z_]+)=(.*)$/m', subject: $environment);

        $envFileWriter = new EnvFileWriter($this->installerOption->targetPath.'/.env.example');

        foreach ($matches[1] as $index => $key) {
            $envFileWriter(key: $key, value: $matches[2][$index]);
        }
    }

    private function composeFileExists(): bool
    {
        if ($this->processRunner->isDryRun()) {
            return false;
        }

        return array_any(['compose.yaml', 'compose.yml', 'docker-compose.yml'], fn ($candidate) => file_exists($this->installerOption->targetPath.'/'.$candidate));
    }

    private function skeletonShipsSail(): bool
    {
        if ($this->processRunner->isDryRun()) {
            return true;
        }

        $raw = file_get_contents($this->installerOption->targetPath.'/composer.json');

        return $raw !== false && str_contains($raw, 'laravel/sail');
    }
}
