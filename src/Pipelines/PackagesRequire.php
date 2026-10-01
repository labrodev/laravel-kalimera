<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\AdditionalPackage;
use Kalimera\Payloads\InstallerOption;

use function Laravel\Prompts\warning;

use Throwable;

/**
 * Every package the answers ask for, required by the host's composer before any container
 * exists: one resolve on the host's own filesystem instead of one `sail composer require`
 * per step.
 *
 * Downloading is all that happens here. The platform pin PhpConstraintApply wrote makes
 * composer resolve for the container's PHP whatever the host runs; scripts and plugins are
 * off because they execute package code, which is the container's job once it is up
 * (DependenciesInstall); and extension requirements are left to the container too, which
 * has the ones a Mac often lacks — pcntl and posix for Horizon among them.
 */
readonly class PackagesRequire implements Pipeline
{
    /**
     * What composer prints when a download or metadata request did not get through, as
     * opposed to a resolution it could not satisfy.
     */
    private const string NETWORK_FAILURE = '/curl error|could not resolve host|connection (timed out|refused|reset)|operation timed out|could not be downloaded|failed to open stream|ssl|network is unreachable/i';

    /**
     * @param  list<AdditionalPackage>  $catalog
     */
    public function __construct(
        private array $catalog,
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private int $retryDelaySeconds = 5,
    ) {}

    public function label(): string
    {
        return 'Downloading packages';
    }

    public function execute(): void
    {
        $this->requireAll(dev: false, packages: $this->packages());
        $this->requireAll(dev: true, packages: $this->devPackages());

        // Optional: a package that cannot be resolved is skipped rather than failing the
        // scaffold, so each goes on its own and cannot take the others down with it.
        foreach ($this->optionalPackages() as $package) {
            $this->requireOptional(dev: false, package: $package);
        }

        foreach ($this->installerOption->extraDevPackages as $package) {
            $this->requireOptional(dev: true, package: $package);
        }
    }

    /**
     * @return list<string>
     */
    private function packages(): array
    {
        $packages = [];

        if ($this->installerOption->wantsHorizon()) {
            $packages[] = 'laravel/horizon';
        }

        if ($this->installerOption->wantsAroundPackage('fortify')) {
            $packages[] = 'laravel/fortify';
        }

        if ($this->installerOption->wantsAroundPackage('ai')) {
            $packages[] = 'laravel/ai';
        }

        if ($this->installerOption->wantsAroundPackage('scout')) {
            $packages[] = 'laravel/scout';
        }

        if ($this->installerOption->installPostmark) {
            // config/mail.php's 'postmark' transport is backed by symfony/mailer, never by
            // Postmark's own SDK, which caps guzzle a major version below Laravel 13's.
            $packages[] = 'symfony/postmark-mailer';
        }

        if ($this->installerOption->installInertia) {
            $packages[] = 'inertiajs/inertia-laravel';
        }

        foreach ($this->selectedAdditionalPackages() as $additionalPackage) {
            if (! $additionalPackage->dev) {
                $packages[] = $additionalPackage->package;
            }
        }

        return $packages;
    }

    /**
     * @return list<string>
     */
    private function devPackages(): array
    {
        $packages = [];

        if ($this->installerOption->wantsQualityTool('pint')) {
            $packages[] = 'laravel/pint';
        }

        if ($this->installerOption->wantsQualityTool('phpstan')) {
            $packages[] = 'larastan/larastan';
            $packages[] = 'barryvdh/laravel-ide-helper';
        }

        if ($this->installerOption->wantsQualityTool('rector')) {
            $packages[] = 'rector/rector';
            $packages[] = 'driftingly/rector-laravel';
        }

        if ($this->installerOption->installBoost) {
            $packages[] = 'laravel/boost';
        }

        if ($this->installerOption->installsVet()) {
            $packages[] = 'laravel/vet';
        }

        foreach ($this->selectedAdditionalPackages() as $additionalPackage) {
            if ($additionalPackage->dev) {
                $packages[] = $additionalPackage->package;
            }
        }

        return $packages;
    }

    /**
     * @return list<string>
     */
    private function optionalPackages(): array
    {
        // Nightwatch is a monitoring agent the application runs fine without, so a release
        // that cannot resolve is skipped rather than stopping the scaffold.
        $packages = $this->installerOption->wantsAroundPackage('nightwatch') ? ['laravel/nightwatch'] : [];

        return [...$packages, ...$this->installerOption->extraPackages];
    }

    /**
     * Selected names keep working even when the catalog no longer lists them (for
     * example resuming after the config changed): they install as plain packages.
     *
     * @return list<AdditionalPackage>
     */
    private function selectedAdditionalPackages(): array
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

    /**
     * @param  list<string>  $packages
     */
    private function requireAll(bool $dev, array $packages): void
    {
        $required = $this->alreadyRequired();

        $packages = array_values(array_filter(
            $packages,
            fn (string $package): bool => ! in_array($this->packageName($package), $required, true),
        ));

        if ($packages === []) {
            return;
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $this->processRunner->runCommand(
                    command: $this->command(dev: $dev, packages: $packages),
                    // Replayed on every attempt: whether this failure is retried is only
                    // decided after it, and a conflict that is not must reach the terminal
                    // with composer's own explanation, not just "Command failed".
                    cwd: $this->installerOption->targetPath,
                );

                return;
            } catch (CommandFailedException $commandFailedException) {
                // A version conflict fails identically however often it is asked, so only
                // a failure that names the network earns another attempt — anything else
                // goes straight to the user. Composer restores composer.json and the lock
                // when a require fails, so a retry starts from the same manifest.
                if ($attempt >= ProcessRunner::NETWORK_ATTEMPTS || ! $this->isNetworkFailure($commandFailedException->output)) {
                    throw $commandFailedException;
                }

                warning(sprintf('Composer could not reach the network — retrying in %d seconds.', $this->retryDelaySeconds));
                sleep($this->retryDelaySeconds);
            }
        }
    }

    private function isNetworkFailure(string $output): bool
    {
        return preg_match(self::NETWORK_FAILURE, $output) === 1;
    }

    /**
     * The name a requirement is listed under in composer.json: a catalog entry can carry its
     * constraint (`vendor/package:^2.0`), which the manifest keeps apart from the name.
     */
    private function packageName(string $package): string
    {
        return strtolower(preg_split('/[:=\s]/', $package)[0] ?? $package);
    }

    /**
     * A starter kit can ship one of these already (the React kit requires Fortify), and a
     * resumed run finds the ones it got to. Requiring them again would only rewrite their
     * constraint to whatever is newest.
     *
     * @return list<string>
     */
    private function alreadyRequired(): array
    {
        if ($this->processRunner->isDryRun()) {
            return [];
        }

        $manifest = json_decode((string) @file_get_contents($this->installerOption->targetPath.'/composer.json'), true);

        if (! is_array($manifest)) {
            return [];
        }

        $required = [];

        foreach (['require', 'require-dev'] as $section) {
            if (is_array($manifest[$section] ?? null)) {
                $required = [...$required, ...array_map(fn (int|string $name): string => strtolower((string) $name), array_keys($manifest[$section]))];
            }
        }

        return $required;
    }

    private function requireOptional(bool $dev, string $package): void
    {
        try {
            $this->requireAll(dev: $dev, packages: [$package]);
        } catch (Throwable $exception) {
            warning(sprintf('%s could not be installed — skipping it. %s', $package, $exception->getMessage()));
        }
    }

    /**
     * @param  list<string>  $packages
     * @return list<string>
     */
    private function command(bool $dev, array $packages): array
    {
        // Options first and the names after `--`, so a name from the config that starts
        // with a dash is read as a package rather than as an option to composer.
        return [
            'composer',
            'require',
            ...($dev ? ['--dev'] : []),
            '--no-scripts',
            '--no-plugins',
            '--ignore-platform-req=ext-*',
            '--no-interaction',
            '--',
            ...$packages,
        ];
    }
}
