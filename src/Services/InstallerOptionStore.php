<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Exceptions\InvalidTargetException;
use Kalimera\Payloads\InstallerOption;

readonly class InstallerOptionStore
{
    public function save(InstallerOption $installerOption): void
    {
        if (! is_dir($installerOption->targetPath)) {
            mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
        }

        $answers = get_object_vars($installerOption);

        // These two describe the invocation, not the answers, and load() always takes
        // them from the current run — persisting them would only mislead a reader.
        unset($answers['dryRun'], $answers['resume']);

        new RunStateFile($installerOption->targetPath)->saveAnswers($answers);
    }

    /**
     * Null when there is nothing to resume from: the previous run died before it saved its
     * answers, so no step past creating the application ran either, and asking again
     * cannot contradict anything.
     *
     * Once there is state on disk, though, the answers are not optional. A plan rebuilt
     * from fresh answers would still skip every step the checkpoint names — steps that ran
     * under the old ones — and finish an application that is half one configuration and
     * half the other, with nothing to say so.
     *
     * @throws InvalidTargetException when state exists but its answers cannot be read
     */
    public function load(string $appName, bool $dryRun, string $targetPath): ?InstallerOption
    {
        $runStateFile = new RunStateFile($targetPath);

        if (! $runStateFile->exists()) {
            return null;
        }

        $decoded = $runStateFile->answers();

        if ($decoded === null || ! isset($decoded['starterKit'], $decoded['phpConstraint'])) {
            throw InvalidTargetException::make(sprintf(
                'Cannot resume %s: %s is there but the answers it should hold are missing or unreadable, and the steps already run used them. Restore the file, or delete the directory and start over.',
                $targetPath,
                RunStateFile::FILENAME,
            ));
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
