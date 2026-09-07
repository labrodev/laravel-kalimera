<?php

declare(strict_types=1);

namespace Kalimera\Services;

use JsonException;
use Kalimera\Payloads\InstallerOption;

readonly class InstallerOptionStore
{
    private const string FILENAME = '.kalimera.json';

    public function save(InstallerOption $installerOption): void
    {
        if (! is_dir($installerOption->targetPath)) {
            mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
        }

        $answers = get_object_vars($installerOption);

        // These two describe the invocation, not the answers, and load() always takes
        // them from the current run — persisting them would only mislead a reader.
        unset($answers['dryRun'], $answers['resume']);

        $encoded = json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        (new FileWriter)(contents: $encoded."\n", path: $this->path($installerOption->targetPath));
    }

    public function load(string $appName, bool $dryRun, string $targetPath): ?InstallerOption
    {
        $path = $this->path($targetPath);

        if (! file_exists($path)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(associative: true, flags: JSON_THROW_ON_ERROR, json: (string) file_get_contents($path));
        } catch (JsonException) {
            return null;
        }

        if (! isset($decoded['starterKit'], $decoded['phpConstraint'])) {
            return null;
        }

        return new InstallerOption(
            appName: $appName,
            targetPath: $targetPath,
            starterKit: (string) $decoded['starterKit'],
            installInertia: (bool) ($decoded['installInertia'] ?? false),
            aroundPackages: $this->stringList($decoded['aroundPackages'] ?? []),
            sailServices: $this->stringList($decoded['sailServices'] ?? []),
            phpConstraint: (string) $decoded['phpConstraint'],
            qualityTools: $this->stringList($decoded['qualityTools'] ?? []),
            additionalPackages: $this->stringList($decoded['additionalPackages'] ?? $decoded['spatiePackages'] ?? []),
            installPostmark: (bool) ($decoded['installPostmark'] ?? false),
            // Absent from a state file written before the option existed, and Boost ran
            // unconditionally then — so its absence has to keep meaning yes.
            installBoost: (bool) ($decoded['installBoost'] ?? true),
            boostAgents: $this->stringList($decoded['boostAgents'] ?? []),
            boostSkillRepos: $this->stringList($decoded['boostSkillRepos'] ?? []),
            extraPackages: $this->stringList($decoded['extraPackages'] ?? []),
            extraDevPackages: $this->stringList($decoded['extraDevPackages'] ?? []),
            dryRun: $dryRun,
            coreNamespace: isset($decoded['coreNamespace']) ? (string) $decoded['coreNamespace'] : null,
            // Reaching the store at all means --continue: whatever the previous run left
            // running — containers, volumes, a partly migrated database — must survive.
            resume: true,
        );
    }

    public function forget(string $targetPath): void
    {
        $path = $this->path($targetPath);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function path(string $targetPath): string
    {
        return $targetPath.'/'.self::FILENAME;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($values, is_scalar(...))));
    }
}
