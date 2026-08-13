<?php

declare(strict_types=1);

namespace Kalimera\Payloads;

readonly class InstallerConfig
{
    /**
     * @param  list<AdditionalPackage>  $additionalPackages
     * @param  list<string>  $aroundPackages
     * @param  list<string>  $sailServices
     * @param  list<string>  $qualityTools
     * @param  list<string>  $boostAgents
     * @param  list<string>  $boostSkillRepos
     * @param  list<string>  $extraPackages
     * @param  list<string>  $extraDevPackages
     */
    public function __construct(
        public array $additionalPackages,
        public string $starterKit,
        public bool $installInertia,
        public array $aroundPackages,
        public array $sailServices,
        public string $phpConstraint,
        public array $qualityTools,
        public bool $installPostmark,
        public array $boostAgents,
        public array $boostSkillRepos,
        public array $extraPackages,
        public array $extraDevPackages,
        public ?string $coreNamespace,
    ) {}

    /**
     * Code-level safety net only: the live built-in setup is read from the packaged
     * schema's default values (see ConfigLoader::packaged()). This hardcoded copy is
     * used solely when that file is missing or omits a key.
     */
    public static function builtIn(): self
    {
        return new self(
            additionalPackages: [
                new AdditionalPackage(
                    label: 'laravel-data — DTOs & validation (replaces Request classes)',
                    package: 'spatie/laravel-data',
                    publishProviders: ['Spatie\\LaravelData\\LaravelDataServiceProvider'],
                ),
                new AdditionalPackage(
                    label: 'laravel-view-models — view models for templates',
                    package: 'spatie/laravel-view-models',
                ),
                new AdditionalPackage(
                    label: 'laravel-query-builder — API-friendly query building',
                    package: 'spatie/laravel-query-builder',
                ),
                new AdditionalPackage(
                    label: 'laravel-backup — application & database backups',
                    package: 'spatie/laravel-backup',
                    publishProviders: ['Spatie\\Backup\\BackupServiceProvider'],
                ),
                new AdditionalPackage(
                    label: 'laravel-permission — roles & permissions',
                    package: 'spatie/laravel-permission',
                    publishProviders: ['Spatie\\Permission\\PermissionServiceProvider'],
                ),
                new AdditionalPackage(
                    label: 'laravel-activitylog — audit log of model changes',
                    package: 'spatie/laravel-activitylog',
                    publishProviders: ['Spatie\\Activitylog\\ActivitylogServiceProvider'],
                ),
                new AdditionalPackage(
                    label: 'laravel-translatable — translatable Eloquent attributes',
                    package: 'spatie/laravel-translatable',
                    publishProviders: ['Spatie\\Translatable\\TranslatableServiceProvider'],
                ),
            ],
            starterKit: 'react',
            installInertia: false,
            aroundPackages: ['horizon', 'fortify', 'ai', 'nightwatch'],
            sailServices: ['pgsql', 'redis'],
            phpConstraint: '^8.5',
            qualityTools: ['pint', 'phpstan', 'rector'],
            installPostmark: false,
            boostAgents: ['claude_code'],
            boostSkillRepos: [],
            extraPackages: [],
            extraDevPackages: [],
            coreNamespace: 'Core',
        );
    }

    /**
     * @return array<string, string> package => label, ready for a multiselect
     */
    public function additionalPackageOptions(): array
    {
        $options = [];

        foreach ($this->additionalPackages as $additionalPackage) {
            $options[$additionalPackage->package] = $additionalPackage->label;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function preselectedAdditionalPackages(): array
    {
        return array_values(array_map(
            fn (AdditionalPackage $additionalPackage): string => $additionalPackage->package,
            array_filter(
                $this->additionalPackages,
                fn (AdditionalPackage $additionalPackage): bool => $additionalPackage->preselected,
            ),
        ));
    }

    public function findAdditionalPackage(string $package): ?AdditionalPackage
    {
        foreach ($this->additionalPackages as $additionalPackage) {
            if ($additionalPackage->package === $package) {
                return $additionalPackage;
            }
        }

        return null;
    }
}
