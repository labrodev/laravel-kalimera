<?php

declare(strict_types=1);

namespace Kalimera\Payloads;

readonly class InstallerOption
{
    /**
     * @param  list<string>  $aroundPackages
     * @param  list<string>  $sailServices
     * @param  list<string>  $qualityTools
     * @param  list<string>  $additionalPackages
     * @param  list<string>  $boostAgents
     * @param  list<string>  $boostSkillRepos
     * @param  list<string>  $extraPackages
     * @param  list<string>  $extraDevPackages
     */
    public function __construct(
        public string $appName,
        public string $targetPath,
        public string $starterKit,
        public bool $installInertia,
        public array $aroundPackages,
        public array $sailServices,
        public string $phpConstraint,
        public array $qualityTools,
        public array $additionalPackages,
        public bool $installPostmark,
        public bool $installBoost,
        public array $boostAgents,
        public array $boostSkillRepos,
        public array $extraPackages,
        public array $extraDevPackages,
        public bool $dryRun,
        public ?string $coreNamespace,
        public bool $resume = false,
    ) {}

    public function usesDatabaseService(): bool
    {
        return in_array('mysql', $this->sailServices, true) || in_array('pgsql', $this->sailServices, true);
    }

    public function phpMinorVersion(): string
    {
        preg_match(pattern: '/(\d+\.\d+)/', subject: $this->phpConstraint, matches: $matches);

        return $matches[1] ?? '8.5';
    }

    public function wantsHorizon(): bool
    {
        return in_array('horizon', $this->aroundPackages, true);
    }

    public function wantsQualityTool(string $tool): bool
    {
        return in_array($tool, $this->qualityTools, true);
    }

    /**
     * The quality tools the QualityToolsInstall step installs. Vet is chosen at the same
     * prompt but is not one of them: it brings a composer plugin that audits every later
     * install, so it has a step of its own at the end of the plan — after every other
     * package is in vendor/, which is exactly what its trust file has to describe.
     */
    public function wantsStaticAnalysis(): bool
    {
        return array_intersect(['pint', 'phpstan', 'rector'], $this->qualityTools) !== [];
    }

    public function wantsAdditionalPackage(string $package): bool
    {
        return in_array($package, $this->additionalPackages, true);
    }
}
