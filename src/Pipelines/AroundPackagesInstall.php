<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\ProvidersRepairFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\EnvFileWriter;
use Kalimera\Services\FileWriter;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

readonly class AroundPackagesInstall implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Setting up the Laravel ecosystem packages';
    }

    public function execute(): void
    {
        if ($this->installerOption->wantsHorizon()) {
            $providersBefore = $this->currentProviders();

            $this->processRunner->runCommand(command: $this->sailCommandBuilder->artisan('horizon:install'), cwd: $this->sailCommandBuilder->path());

            $this->repairProvidersFile(expected: [...$providersBefore, 'App\\Providers\\HorizonServiceProvider']);
        }

        if (in_array('fortify', $this->installerOption->aroundPackages, true) && ! $this->fortifyAlreadyInstalled()) {
            $providersBefore = $this->currentProviders();

            $this->processRunner->runCommand(command: $this->sailCommandBuilder->artisan('fortify:install'), cwd: $this->sailCommandBuilder->path());

            $this->repairProvidersFile(expected: [...$providersBefore, 'App\\Providers\\FortifyServiceProvider']);
        }

        if ($this->installerOption->wantsAroundPackage('ai')) {
            // By provider, as Laravel AI's install instructions have it: the conversations
            // migration is registered under the provider alone, with no tag to ask for it
            // by, so a tag-filtered publish copies the config and silently skips the table.
            $this->processRunner->runCommand(
                command: $this->sailCommandBuilder->artisan('vendor:publish', '--provider=Laravel\\Ai\\AiServiceProvider', '--no-interaction'),
                cwd: $this->sailCommandBuilder->path(),
            );
        }

        if ($this->installerOption->wantsAroundPackage('scout')) {
            $this->processRunner->runCommand(
                command: $this->sailCommandBuilder->artisan('vendor:publish', '--provider=Laravel\\Scout\\ScoutServiceProvider', '--no-interaction'),
                cwd: $this->sailCommandBuilder->path(),
            );

            $this->configureScoutDriver();
        }
    }

    /**
     * Scout's own default is the collection engine, which loads every candidate row and
     * filters it in PHP — fine for tests, not for real data. With PostgreSQL or MySQL the
     * database engine searches the application's own tables with full-text queries, so search
     * works on day one with nothing else to run; moving to Meilisearch or Typesense later is
     * one .env line. Without either database the collection engine is all there is.
     */
    private function configureScoutDriver(): void
    {
        $driver = $this->installerOption->usesDatabaseService() ? 'database' : 'collection';

        $this->processRunner->applyFileChange(
            action: function () use ($driver): void {
                foreach (['.env', '.env.example'] as $file) {
                    new EnvFileWriter($this->installerOption->targetPath.'/'.$file)(key: 'SCOUT_DRIVER', value: $driver);
                }
            },
            description: 'set SCOUT_DRIVER='.$driver.' in .env and .env.example',
        );
    }

    /**
     * Asks what `fortify:install` leaves behind rather than whether composer.json requires
     * the package. The requirement alone cannot tell a starter kit that ships Fortify apart
     * from kalimera's own `composer require` on the line above — so a run that required the
     * package and then died before installing it used to look finished, and the replay under
     * --continue skipped the install that never happened, delivering a composer requirement
     * with no config, no actions and no registered provider, over an exit code of 0.
     *
     * The published config is the marker because it appears in both legitimate cases and in
     * neither failure: a kit that bundles Fortify has it straight out of `laravel new`, and
     * an earlier run that got as far as publishing it has genuinely finished this work.
     */
    private function fortifyAlreadyInstalled(): bool
    {
        if ($this->processRunner->isDryRun()) {
            return false;
        }

        if (! file_exists($this->installerOption->targetPath.'/config/fortify.php')) {
            return false;
        }

        info('Fortify is already installed — skipping it.');

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

        if ($this->processRunner->probe(command: ['php', '-l', $path]) && ! str_contains($contents, '1::class')) {
            return;
        }

        warning('horizon:install corrupted bootstrap/providers.php — repairing it automatically.');

        $providers = array_values(array_unique($expected));
        sort($providers);

        $lines = array_map(fn (string $provider): string => '    '.$provider.'::class,', $providers);

        (new FileWriter)(contents: "<?php\n\nreturn [\n".implode("\n", $lines)."\n];\n", path: $path);

        if (! $this->processRunner->probe(command: ['php', '-l', $path])) {
            throw ProvidersRepairFailedException::make($path);
        }
    }
}
