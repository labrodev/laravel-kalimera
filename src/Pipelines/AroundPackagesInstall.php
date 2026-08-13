<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\ProvidersRepairFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\FileWriter;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

use Throwable;

readonly class AroundPackagesInstall implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Installing Laravel ecosystem packages';
    }

    public function execute(): void
    {
        if ($this->installerOption->wantsHorizon()) {
            $providersBefore = $this->currentProviders();

            $this->processRunner->runCommand(attempts: 3, command: $this->sailCommandBuilder->composer('require', 'laravel/horizon'), cwd: $this->sailCommandBuilder->path());
            $this->processRunner->runCommand(command: $this->sailCommandBuilder->artisan('horizon:install'), cwd: $this->sailCommandBuilder->path());

            $this->repairProvidersFile(expected: [...$providersBefore, 'App\\Providers\\HorizonServiceProvider']);
        }

        if (in_array('fortify', $this->installerOption->aroundPackages, true) && ! $this->fortifyAlreadyInstalled()) {
            $providersBefore = $this->currentProviders();

            $this->processRunner->runCommand(attempts: 3, command: $this->sailCommandBuilder->composer('require', 'laravel/fortify'), cwd: $this->sailCommandBuilder->path());
            $this->processRunner->runCommand(command: $this->sailCommandBuilder->artisan('fortify:install'), cwd: $this->sailCommandBuilder->path());

            $this->repairProvidersFile(expected: [...$providersBefore, 'App\\Providers\\FortifyServiceProvider']);
        }

        if (in_array('ai', $this->installerOption->aroundPackages, true)) {
            $this->softRequire('laravel/ai');
        }

        if (in_array('nightwatch', $this->installerOption->aroundPackages, true)) {
            $this->softRequire('laravel/nightwatch');
        }
    }

    private function fortifyAlreadyInstalled(): bool
    {
        if ($this->processRunner->isDryRun()) {
            return false;
        }

        $composerJson = (string) file_get_contents($this->installerOption->targetPath.'/composer.json');

        if (! str_contains($composerJson, 'laravel/fortify')) {
            return false;
        }

        info('Fortify already ships with the chosen starter kit — skipping it.');

        return true;
    }

    /**
     * @return list<string>
     */
    private function currentProviders(): array
    {
        if ($this->processRunner->isDryRun()) {
            return [];
        }

        $contents = (string) file_get_contents($this->installerOption->targetPath.'/bootstrap/providers.php');

        preg_match_all(matches: $matches, pattern: '/^\s+([A-Za-z0-9_\\\\]+)::class,?$/m', subject: $contents);

        return $matches[1] === [] ? ['App\\Providers\\AppServiceProvider'] : $matches[1];
    }

    /**
     * addProviderToBootstrapFile() in the framework intermittently mangles the providers
     * array on a first run (observed as `1::class`). Rewrite the file from the snapshot
     * taken before horizon:install instead of aborting the whole scaffold.
     *
     * @param  list<string>  $expected
     */
    private function repairProvidersFile(array $expected): void
    {
        if ($this->processRunner->isDryRun()) {
            return;
        }

        $path = $this->installerOption->targetPath.'/bootstrap/providers.php';
        $contents = (string) file_get_contents($path);

        if ($this->processRunner->runCommandQuietly(command: ['php', '-l', $path]) && ! str_contains($contents, '1::class')) {
            return;
        }

        warning('horizon:install corrupted bootstrap/providers.php — repairing it automatically.');

        $providers = array_values(array_unique($expected));
        sort($providers);

        $lines = array_map(fn (string $provider): string => '    '.$provider.'::class,', $providers);

        (new FileWriter)(contents: "<?php\n\nreturn [\n".implode("\n", $lines)."\n];\n", path: $path);

        if (! $this->processRunner->runCommandQuietly(command: ['php', '-l', $path])) {
            throw ProvidersRepairFailedException::make($path);
        }
    }

    private function softRequire(string $package): void
    {
        try {
            $this->processRunner->runCommand(attempts: 3, command: $this->sailCommandBuilder->composer('require', $package), cwd: $this->sailCommandBuilder->path());
        } catch (Throwable $exception) {
            warning(sprintf('%s could not be installed — skipping it. %s', $package, $exception->getMessage()));
        }
    }
}
