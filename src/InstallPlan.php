<?php

declare(strict_types=1);

namespace Kalimera;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\PortChecker;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerConfig;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\AdditionalPackagesInstall;
use Kalimera\Pipelines\AgentGuardConfigure;
use Kalimera\Pipelines\AppCreate;
use Kalimera\Pipelines\AppFinalize;
use Kalimera\Pipelines\AroundPackagesInstall;
use Kalimera\Pipelines\BoostInstall;
use Kalimera\Pipelines\CoreStructureScaffold;
use Kalimera\Pipelines\ExtraPackagesInstall;
use Kalimera\Pipelines\InertiaInstall;
use Kalimera\Pipelines\PhpConstraintApply;
use Kalimera\Pipelines\PostmarkInstall;
use Kalimera\Pipelines\QualityToolsInstall;
use Kalimera\Pipelines\SailInstall;
use Kalimera\Pipelines\SailPortsConfigure;
use Kalimera\Pipelines\SailRuntimeConfigure;
use Kalimera\Pipelines\SailStart;
use Kalimera\Pipelines\VetInstall;
use Kalimera\Services\ComposerManifestGuard;
use Kalimera\Services\NetworkPortChecker;
use Kalimera\Services\SailCommandBuilder;

/**
 * The ordered list of steps a set of answers turns into. Its ordering rules — what must
 * come before what, and why — are asserted by name in InstallPlanTest, so a reordering
 * that breaks one fails on the rule rather than on a snapshot diff.
 */
readonly class InstallPlan
{
    public function __construct(private PortChecker $portChecker = new NetworkPortChecker) {}

    /**
     * @return list<Pipeline>
     */
    public function steps(InstallerConfig $installerConfig, InstallerOption $installerOption, ProcessRunner $processRunner, ?string $transcriptFile = null): array
    {
        $sailCommandBuilder = new SailCommandBuilder(appPath: $installerOption->targetPath);

        $processRunner = new ComposerManifestGuard(
            processRunner: $processRunner,
            sailCommandBuilder: $sailCommandBuilder,
            targetPath: $installerOption->targetPath,
        );

        $steps = [
            new AppCreate(installerOption: $installerOption, processRunner: $processRunner, transcriptFile: $transcriptFile),
            new SailInstall(installerOption: $installerOption, processRunner: $processRunner),
            new SailRuntimeConfigure(installerOption: $installerOption, processRunner: $processRunner),
            new SailPortsConfigure(installerOption: $installerOption, portChecker: $this->portChecker, processRunner: $processRunner),
            new SailStart(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder, portChecker: $this->portChecker),
            new PhpConstraintApply(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder),
        ];

        if ($installerOption->aroundPackages !== []) {
            $steps[] = new AroundPackagesInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->wantsStaticAnalysis()) {
            $steps[] = new QualityToolsInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->additionalPackages !== []) {
            $steps[] = new AdditionalPackagesInstall(catalog: $installerConfig->additionalPackages, installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->installBoost) {
            $steps[] = new BoostInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->installPostmark) {
            $steps[] = new PostmarkInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->installInertia) {
            $steps[] = new InertiaInstall(processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->coreNamespace !== null) {
            $steps[] = new CoreStructureScaffold(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->extraPackages !== [] || $installerOption->extraDevPackages !== []) {
            $steps[] = new ExtraPackagesInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        // After every step that installs a package, and before the ones that do not: the
        // trust file vet records here has to describe the finished vendor directory, and
        // its composer plugin fails any install of a package it does not already cover.
        if ($installerOption->wantsQualityTool('vet')) {
            $steps[] = new VetInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        $steps[] = new AgentGuardConfigure(installerOption: $installerOption, processRunner: $processRunner);

        $steps[] = new AppFinalize(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);

        return $steps;
    }
}
