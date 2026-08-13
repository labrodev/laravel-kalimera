<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\warning;

use Throwable;

readonly class ExtraPackagesInstall implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Installing extra packages';
    }

    public function execute(): void
    {
        $this->requireAll(dev: false, packages: $this->installerOption->extraPackages);
        $this->requireAll(dev: true, packages: $this->installerOption->extraDevPackages);
    }

    /**
     * @param  list<string>  $packages
     */
    private function requireAll(bool $dev, array $packages): void
    {
        foreach ($packages as $package) {
            $arguments = $dev ? ['require', '--dev', $package] : ['require', $package];

            try {
                $this->processRunner->runCommand(
                    command: $this->sailCommandBuilder->composer(...$arguments),
                    cwd: $this->sailCommandBuilder->path(),
                );
            } catch (Throwable $exception) {
                warning(sprintf('%s could not be installed — skipping it. %s', $package, $exception->getMessage()));
            }
        }
    }
}
