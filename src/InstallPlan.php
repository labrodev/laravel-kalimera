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
use Kalimera\Pipelines\DependenciesInstall;
use Kalimera\Pipelines\InertiaInstall;
use Kalimera\Pipelines\PackagesRequire;
use Kalimera\Pipelines\PhpConstraintApply;
use Kalimera\Pipelines\PostmarkInstall;
use Kalimera\Pipelines\QualityToolsInstall;
use Kalimera\Pipelines\SailInstall;
use Kalimera\Pipelines\SailPortsConfigure;
use Kalimera\Pipelines\SailStart;
use Kalimera\Pipelines\VetInstall;
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

        // Host phase: every file the scaffold writes and every package it downloads, before
        // any container exists — one composer resolve on the host's own filesystem instead
        // of one `sail composer require` per step.
        $steps = [
            new AppCreate(installerOption: $installerOption, processRunner: $processRunner, transcriptFile: $transcriptFile),
            // Host artisan runs here and nowhere later: once the platform pin below is in,
            // vendor/ targets the container's PHP and may not load on the host's.
            new SailInstall(installerOption: $installerOption, processRunner: $processRunner),
            new PhpConstraintApply(installerOption: $installerOption, processRunner: $processRunner),
        ];

        if ($installerOption->wantsStaticAnalysis()) {
            $steps[] = new QualityToolsInstall(installerOption: $installerOption, processRunner: $processRunner);
        }

        if ($installerOption->wantsQualityTool('vet')) {
            $steps[] = new VetInstall(installerOption: $installerOption, processRunner: $processRunner);
        }

        if ($installerOption->installPostmark) {
            $steps[] = new PostmarkInstall(installerOption: $installerOption, processRunner: $processRunner);
        }

        if ($installerOption->coreNamespace !== null) {
            $steps[] = new CoreStructureScaffold(installerOption: $installerOption, processRunner: $processRunner);
        }

        $steps[] = new PackagesRequire(catalog: $installerConfig->additionalPackages, installerOption: $installerOption, processRunner: $processRunner);

        $steps[] = new AgentGuardConfigure(installerOption: $installerOption, processRunner: $processRunner);

        // Container phase: everything that executes application or package code, on the
        // PHP the application was pinned to. Ports are probed immediately before they are
        // bound — probed any earlier, the downloads above leave a minute or more for another
        // program to take one.
        $steps[] = new SailPortsConfigure(installerOption: $installerOption, portChecker: $this->portChecker, processRunner: $processRunner);
        $steps[] = new SailStart(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder, portChecker: $this->portChecker);
        $steps[] = new DependenciesInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);

        if (array_intersect(['horizon', 'fortify', 'ai', 'scout'], $installerOption->aroundPackages) !== []) {
            $steps[] = new AroundPackagesInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->additionalPackages !== []) {
            $steps[] = new AdditionalPackagesInstall(catalog: $installerConfig->additionalPackages, installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->installBoost) {
            $steps[] = new BoostInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->installInertia) {
            $steps[] = new InertiaInstall(processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        $steps[] = new AppFinalize(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);

        return $steps;
    }
}
