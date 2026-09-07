<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\FileWriter;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\warning;

use Throwable;

readonly class BoostInstall implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Installing Laravel Boost';
    }

    public function execute(): void
    {
        $this->processRunner->runCommand(
            attempts: ProcessRunner::NETWORK_ATTEMPTS,
            command: $this->sailCommandBuilder->composer('require', 'laravel/boost', '--dev'),
            cwd: $this->sailCommandBuilder->path(),
        );

        if ($this->installerOption->boostAgents === []) {
            $this->processRunner->runCommand(
                command: $this->sailCommandBuilder->artisan('boost:install', '--no-interaction'),
                cwd: $this->sailCommandBuilder->path(),
            );
        } else {
            $this->processRunner->applyFileChange(
                action: fn () => $this->writeBoostConfig(),
                description: 'preconfigure boost.json with agents: '.implode(', ', $this->installerOption->boostAgents),
            );

            $this->processRunner->runCommand(
                command: $this->sailCommandBuilder->artisan('boost:install', '--guidelines', '--skills', '--mcp', '--no-interaction'),
                cwd: $this->sailCommandBuilder->path(),
            );
        }

        foreach ($this->installerOption->boostSkillRepos as $repository) {
            try {
                $this->processRunner->runCommand(
                    command: $this->sailCommandBuilder->artisan('boost:add-skill', $repository, '--all', '--no-interaction'),
                    cwd: $this->sailCommandBuilder->path(),
                );
            } catch (Throwable $exception) {
                warning(sprintf('Skills from %s could not be added — skipping it. %s', $repository, $exception->getMessage()));
            }
        }

        $this->updateGuidelines();
    }

    /**
     * `boost:install` writes whatever guidance the pinned release happens to bundle, so a
     * scaffold created the day before a Boost release starts on stale rules. `boost:update`
     * refreshes the guidelines and skills that install just wrote, and it runs last so the
     * repositories added above are refreshed along with them.
     *
     * Discovery is off because it exists only to prompt, and there is nobody here to answer:
     * the command already declines to prompt on a non-interactive input, but that is a
     * property of how sail happens to attach the terminal rather than something this
     * pipeline controls, and a scaffold that stops dead on a hidden multiselect is the one
     * outcome worth ruling out by hand.
     *
     * Failure is a warning rather than an abort. The update is a refresh of guidance that
     * install has already written — the application is complete and correct without it —
     * and boost:update exits non-zero on its own for a project whose boost.json carries no
     * agents, which is exactly the shape the unattended install leaves behind when it
     * detects none. Losing the whole scaffold at step 10 over newer wording is a worse
     * trade than starting on the bundled guidance.
     */
    private function updateGuidelines(): void
    {
        try {
            $this->processRunner->runCommand(
                command: $this->sailCommandBuilder->artisan('boost:update', '--no-discover', '--no-interaction'),
                cwd: $this->sailCommandBuilder->path(),
            );
        } catch (Throwable $exception) {
            warning(sprintf('Boost guidelines could not be updated — the bundled guidance was kept. %s', $exception->getMessage()));
        }
    }

    private function writeBoostConfig(): void
    {
        $path = $this->installerOption->targetPath.'/boost.json';

        /** @var array<string, mixed> $config */
        $config = file_exists($path)
            ? (array) json_decode(associative: true, flags: JSON_THROW_ON_ERROR, json: (string) file_get_contents($path))
            : [];

        $config['agents'] = $this->installerOption->boostAgents;

        (new FileWriter)(
            contents: json_encode(flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, value: $config)."\n",
            path: $path,
        );
    }
}
