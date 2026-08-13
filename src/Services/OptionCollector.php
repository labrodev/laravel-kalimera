<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Exceptions\InvalidTargetException;
use Kalimera\Payloads\InstallerConfig;
use Kalimera\Payloads\InstallerOption;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

readonly class OptionCollector
{
    public const array STARTER_KITS = [
        'react' => 'React (Inertia included)',
        'vue' => 'Vue (Inertia included)',
        'livewire' => 'Livewire',
        'svelte' => 'Svelte (Inertia included)',
        'none' => 'None — plain Laravel skeleton',
    ];

    public const array AROUND_PACKAGES = [
        'horizon' => 'Horizon — Redis queue dashboard & supervisor',
        'fortify' => 'Fortify — headless authentication backend (starter kits already include it)',
        'ai' => 'Laravel AI — official AI SDK (laravel/ai)',
        'nightwatch' => 'Nightwatch — monitoring agent (laravel/nightwatch)',
    ];

    public const array SAIL_SERVICES = [
        'pgsql' => 'PostgreSQL',
        'redis' => 'Redis',
        'mysql' => 'MySQL',
        'mailpit' => 'Mailpit',
        'meilisearch' => 'Meilisearch',
        'minio' => 'MinIO',
    ];

    public const array QUALITY_TOOLS = [
        'pint' => 'Pint — code style (pint.json)',
        'phpstan' => 'PHPStan + Larastan — static analysis (phpstan.neon.dist)',
        'rector' => 'Rector + rector-laravel — automated refactoring (rector.php)',
    ];

    public const array BOOST_AGENTS = [
        'claude_code' => 'Claude Code',
        'cursor' => 'Cursor',
        'codex' => 'Codex',
        'copilot' => 'GitHub Copilot (VS Code)',
        'junie' => 'Junie (JetBrains)',
        'zed' => 'Zed',
        'opencode' => 'OpenCode',
        'amp' => 'Amp',
        'antigravity' => 'Antigravity',
        'kiro' => 'Kiro',
        'factory' => 'Factory',
        'pi' => 'Pi',
        'grok_build' => 'Grok Build',
    ];

    public const string NAMESPACE_PATTERN = '/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)*$/';

    public function __invoke(bool $dryRun, InstallerConfig $installerConfig, ?string $presetName, bool $resume, bool $useDefaults): InstallerOption
    {
        $targetResolver = new TargetResolver;

        $rawTarget = $presetName ?? text(
            hint: 'A plain name is created in '.(string) getcwd().'; a path like ~/www/my-app works too.',
            label: 'What should the application be named?',
            placeholder: 'my-app or ~/www/my-app',
            required: true,
            validate: fn (string $value): ?string => $targetResolver->validate($value),
        );

        $targetError = $targetResolver->validate($rawTarget);

        if ($targetError !== null) {
            throw InvalidTargetException::make($targetError);
        }

        [$appName, $targetPath] = $targetResolver->resolve($rawTarget);

        if (is_dir($targetPath) && ! $dryRun && ! $resume) {
            throw InvalidTargetException::make(sprintf(
                'Directory %s already exists. Re-run with --continue to resume a failed installation there.',
                $targetPath,
            ));
        }

        if ($resume) {
            $storedInstallerOption = (new InstallerOptionStore)->load(
                appName: $appName,
                dryRun: $dryRun,
                targetPath: $targetPath,
            );

            if ($storedInstallerOption !== null) {
                info('Resuming with the answers saved from the previous run.');

                return $storedInstallerOption;
            }
        }

        if ($useDefaults) {
            return $this->defaults(appName: $appName, dryRun: $dryRun, installerConfig: $installerConfig, targetPath: $targetPath);
        }

        $starterKit = (string) select(
            default: $installerConfig->starterKit,
            label: 'Which starter kit should be installed?',
            options: self::STARTER_KITS,
        );

        $installInertia = $starterKit === 'none' && confirm(
            default: $installerConfig->installInertia,
            label: 'Install Inertia manually (inertiajs/inertia-laravel)?',
        );

        $aroundPackages = $this->stringList(multiselect(
            default: $installerConfig->aroundPackages,
            hint: 'Sail is always installed; Boost has its own dedicated step.',
            label: 'Which Laravel ecosystem packages should be installed?',
            options: self::AROUND_PACKAGES,
        ));

        $sailServices = $this->stringList(multiselect(
            default: $installerConfig->sailServices,
            hint: 'Select none for the application container only — the app then keeps the sqlite database.',
            label: 'Which Sail services should be configured?',
            options: self::SAIL_SERVICES,
        ));

        $sailServices = $this->withRedisForHorizon(aroundPackages: $aroundPackages, sailServices: $sailServices);

        $phpOptions = [
            '^8.5' => '^8.5 — the freshest',
            '^8.4' => '^8.4',
            'custom' => 'Custom constraint…',
        ];

        if (! isset($phpOptions[$installerConfig->phpConstraint])) {
            $phpOptions = [$installerConfig->phpConstraint => $installerConfig->phpConstraint.' — from config'] + $phpOptions;
        }

        $phpChoice = (string) select(
            default: $installerConfig->phpConstraint,
            label: 'Which PHP version constraint should the application require?',
            options: $phpOptions,
        );

        $phpConstraint = $phpChoice !== 'custom' ? $phpChoice : text(
            default: $installerConfig->phpConstraint,
            label: 'Enter the PHP version constraint',
            placeholder: '~8.5.0',
            required: true,
            validate: fn (string $value): ?string => preg_match(pattern: '/\d+\.\d+/', subject: $value) === 1
                ? null
                : 'The constraint must contain a version like 8.5.',
        );

        $qualityTools = $this->stringList(multiselect(
            default: $installerConfig->qualityTools,
            label: 'Which static analysis / code quality tools should be set up?',
            options: self::QUALITY_TOOLS,
        ));

        $additionalPackages = $this->stringList(multiselect(
            default: $installerConfig->preselectedAdditionalPackages(),
            hint: 'The list comes from kalimera.config.json when present — edit it to offer your own catalog.',
            label: 'Which additional packages should be installed?',
            options: $installerConfig->additionalPackageOptions(),
        ));

        $coreNamespace = null;

        if (confirm(
            default: $installerConfig->coreNamespace !== null,
            label: 'Scaffold the Core structure (src/{Domain,Shared,Support,Feature,Infrastructure})?',
        )) {
            $coreNamespace = text(
                default: $installerConfig->coreNamespace ?? 'Core',
                hint: 'PSR-4 namespace mapped to src/, e.g. Core, Src or Acme\\Core.',
                label: 'Which namespace should map to src/?',
                required: true,
                validate: fn (string $value): ?string => preg_match(pattern: self::NAMESPACE_PATTERN, subject: trim($value, '\\')) === 1
                    ? null
                    : 'Use StudlyCase segments separated by backslashes, e.g. Core or Acme\\Core.',
            );

            $coreNamespace = trim($coreNamespace, '\\');
        }

        $installPostmark = confirm(
            default: $installerConfig->installPostmark,
            label: 'Set up the Postmark SDK (wildbit/postmark-php)?',
        );

        $boostAgents = $this->stringList(multiselect(
            default: $installerConfig->boostAgents,
            hint: 'Preconfigures boost:install so it runs without prompts. Select none to let Boost auto-detect your installed agents.',
            label: 'Which AI agents should Laravel Boost set up?',
            options: self::BOOST_AGENTS,
            scroll: 8,
        ));

        $packageListParser = new PackageListParser;

        $boostSkillRepos = $packageListParser(answer: text(
            default: implode(' ', $installerConfig->boostSkillRepos),
            hint: 'Space or comma separated — each one is passed to `artisan boost:add-skill`. Leave empty to skip.',
            label: 'GitHub repositories with your Boost skills',
            placeholder: 'owner/repo another-owner/repo or https://github.com/owner/repo',
        ));

        $extraPackages = $packageListParser(answer: text(
            default: implode(' ', $installerConfig->extraPackages),
            hint: 'Space or comma separated composer package names. Leave empty to skip.',
            label: 'Any extra packages to require?',
            placeholder: 'vendor/package another/package:^2.0',
        ));

        $extraDevPackages = $packageListParser(answer: text(
            default: implode(' ', $installerConfig->extraDevPackages),
            hint: 'Space or comma separated composer package names. Leave empty to skip.',
            label: 'Any extra dev packages to require (--dev)?',
            placeholder: 'barryvdh/laravel-debugbar',
        ));

        return new InstallerOption(
            appName: $appName,
            targetPath: $targetPath,
            starterKit: $starterKit,
            installInertia: $installInertia,
            aroundPackages: $aroundPackages,
            sailServices: $sailServices,
            phpConstraint: $phpConstraint,
            qualityTools: $qualityTools,
            additionalPackages: $additionalPackages,
            installPostmark: $installPostmark,
            coreNamespace: $coreNamespace,
            boostAgents: $boostAgents,
            boostSkillRepos: $boostSkillRepos,
            extraPackages: $extraPackages,
            extraDevPackages: $extraDevPackages,
            dryRun: $dryRun,
        );
    }

    private function defaults(string $appName, bool $dryRun, InstallerConfig $installerConfig, string $targetPath): InstallerOption
    {
        return new InstallerOption(
            appName: $appName,
            targetPath: $targetPath,
            starterKit: $installerConfig->starterKit,
            installInertia: $installerConfig->starterKit === 'none' && $installerConfig->installInertia,
            aroundPackages: $installerConfig->aroundPackages,
            sailServices: $this->withRedisForHorizon(
                aroundPackages: $installerConfig->aroundPackages,
                sailServices: $installerConfig->sailServices,
            ),
            phpConstraint: $installerConfig->phpConstraint,
            qualityTools: $installerConfig->qualityTools,
            additionalPackages: $installerConfig->preselectedAdditionalPackages(),
            installPostmark: $installerConfig->installPostmark,
            coreNamespace: $installerConfig->coreNamespace,
            boostAgents: $installerConfig->boostAgents,
            boostSkillRepos: $installerConfig->boostSkillRepos,
            extraPackages: $installerConfig->extraPackages,
            extraDevPackages: $installerConfig->extraDevPackages,
            dryRun: $dryRun,
        );
    }

    /**
     * Horizon runs on Redis, so choosing it implies the Redis service.
     *
     * @param  list<string>  $aroundPackages
     * @param  list<string>  $sailServices
     * @return list<string>
     */
    private function withRedisForHorizon(array $aroundPackages, array $sailServices): array
    {
        if (in_array('horizon', $aroundPackages, true) && ! in_array('redis', $sailServices, true)) {
            $sailServices[] = 'redis';
        }

        return $sailServices;
    }

    /**
     * @param  array<int|string>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_map(strval(...), array_values($values));
    }
}
