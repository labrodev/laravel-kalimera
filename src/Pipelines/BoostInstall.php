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
            attempts: 3,
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
