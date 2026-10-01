<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\AdditionalPackage;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\SailCommandBuilder;

readonly class AdditionalPackagesInstall implements Pipeline
{
    /**
     * @param  list<AdditionalPackage>  $catalog
     */
    public function __construct(
        private array $catalog,
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Publishing additional package configuration';
    }

    public function execute(): void
    {
        foreach ($this->selected() as $additionalPackage) {
            foreach ($additionalPackage->publishProviders as $provider) {
                $this->processRunner->runCommand(
                    command: $this->sailCommandBuilder->artisan('vendor:publish', '--provider='.$provider, '--no-interaction'),
                    cwd: $this->sailCommandBuilder->path(),
                );
            }
        }
    }

    /**
     * Selected names keep working even when the catalog no longer lists them (for
     * example resuming after the config changed): they install as plain packages.
     *
     * @return list<AdditionalPackage>
     */
    private function selected(): array
    {
        $known = [];

        foreach ($this->catalog as $additionalPackage) {
            $known[$additionalPackage->package] = $additionalPackage;
        }

        return array_map(
            fn (string $package): AdditionalPackage => $known[$package] ?? new AdditionalPackage(label: $package, package: $package),
            $this->installerOption->additionalPackages,
        );
    }
}
