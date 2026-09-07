<?php

declare(strict_types=1);

namespace Kalimera;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\PortChecker;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\Argument;
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
use Kalimera\Pipelines\PreflightCheck;
use Kalimera\Pipelines\QualityToolsInstall;
use Kalimera\Pipelines\SailInstall;
use Kalimera\Pipelines\SailPortsConfigure;
use Kalimera\Pipelines\SailRuntimeConfigure;
use Kalimera\Pipelines\SailStart;
use Kalimera\Services\CommandOutputPrinter;
use Kalimera\Services\ComposerManifestGuard;
use Kalimera\Services\ConfigLoader;
use Kalimera\Services\NetworkPortChecker;
use Kalimera\Services\OptionCollector;
use Kalimera\Services\RunLock;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Services\ShellRunner;
use Kalimera\Services\StepCheckpoint;
use Kalimera\Services\TranscriptLogger;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

use Symfony\Component\Process\ExecutableFinder;
use Throwable;

readonly class KalimeraInstaller
{
    /**
     * Default transcript file, written inside the created application and gitignored there.
     */
    private const string TRANSCRIPT_FILE = 'kalimera.log';

    /**
     * Steps that run on --continue even when the checkpoint says they finished, because
     * what they leave behind is a live process rather than a file: the containers were
     * started once, which says nothing about whether they are up now — a reboot between
     * the two runs is enough to make it false, and every later step talks to them through
     * sail. `up -d --wait` costs seconds when they are already running, so re-running is
     * the cheap side of the trade.
     */
    private const array ALWAYS_RUN = [SailStart::class];

    public function __construct(
        private ?ExecutableFinder $executableFinder = null,
        private PortChecker $portChecker = new NetworkPortChecker,
        private ?ProcessRunner $processRunner = null,
    ) {}

    /**
     * @param  list<string>  $arguments
     */
    public function execute(array $arguments): int
    {
        $argv = $arguments;
        $argument = Argument::fromArgv($argv);

        if ($argument->command !== 'new') {
            $this->usage();

            return $argument->command === null || in_array($argument->command, ['help'], true) ? 0 : 1;
        }

        $this->sunrise();
        intro('Kalimera ☀️! It is time to prepare the freshest Laravel for new exciting project');

        if ($argument->dryRun) {
            warning('Dry run: commands are printed, nothing is executed.');
        }

        // An explicit --log=path is honoured as given; a bare --log buffers until the
        // application directory exists and then lands inside it.
        $transcriptLogger = $argument->logPath === null
            ? null
            : new TranscriptLogger($argument->logPath === '' ? null : $argument->logPath);

        // A dry run executes nothing, so there is no output to condense and nothing the
        // flag would change.
        if (! $argument->verbose && ! $argument->dryRun) {
            info('Command output is condensed to a progress line — pass --verbose to stream it in full.');
        }

        try {
            $processRunner = $this->processRunner ?? new ShellRunner(
                dryRun: $argument->dryRun,
                transcriptLogger: $transcriptLogger,
                commandOutputPrinter: new CommandOutputPrinter(verbose: $argument->verbose),
            );

            $transcriptLogger?->begin($argv);

            new PreflightCheck(
                executableFinder: $this->executableFinder ?? new ExecutableFinder,
                processRunner: $processRunner,
            )->execute();

            $installerConfig = (new ConfigLoader)(configPath: $argument->configPath);

            $installerOption = (new OptionCollector)(
                dryRun: $argument->dryRun,
                installerConfig: $installerConfig,
                presetName: $argument->presetName,
                resume: $argument->resume,
                useDefaults: $argument->useDefaults,
            );

            $runLock = new RunLock;
            $runLock->acquire($installerOption->targetPath);

            if ($argument->logPath === '') {
                $transcriptLogger?->useFile($installerOption->targetPath.'/'.self::TRANSCRIPT_FILE);
            }

            $this->summarize($installerOption);

            if (! $argument->useDefaults && ! confirm(default: true, label: 'Scaffold the application now?')) {
                outro('Nothing was installed.');

                return 0;
            }

            $steps = $this->steps(
                installerConfig: $installerConfig,
                installerOption: $installerOption,
                processRunner: $processRunner,
                transcriptFile: $argument->logPath === '' ? self::TRANSCRIPT_FILE : null,
            );
            $total = count($steps);

            $stepCheckpoint = new StepCheckpoint(
                targetPath: $installerOption->targetPath,
                enabled: ! $argument->dryRun,
            );

            foreach ($steps as $index => $step) {
                if ($this->isSettled(installerOption: $installerOption, step: $step, stepCheckpoint: $stepCheckpoint)) {
                    info(sprintf('%s Step %d/%d — %s (done by the previous run — skipping)', PHP_EOL.'▶', $index + 1, $total, $step->label()));
                    $transcriptLogger?->stepSkipped(index: $index + 1, label: $step->label(), total: $total);

                    continue;
                }

                info(sprintf('%s Step %d/%d — %s', PHP_EOL.'▶', $index + 1, $total, $step->label()));
                $transcriptLogger?->step(index: $index + 1, label: $step->label(), total: $total);
                $step->execute();
                $stepCheckpoint->record($step);
            }

            // The plan finished, so there is nothing left to resume into.
            $stepCheckpoint->forget();

            $this->farewell($installerOption);

            $transcriptLogger?->outcome('completed successfully');
            $this->rescueTranscript($transcriptLogger);

            return 0;
        } catch (Throwable $exception) {
            $transcriptLogger?->outcome($exception->getMessage());
            $this->rescueTranscript($transcriptLogger);

            error($exception->getMessage());
            error('Scaffolding stopped. Fix the issue above and re-run, or continue manually inside the app directory.');

            return 1;
        }
    }

    private function isSettled(InstallerOption $installerOption, Pipeline $step, StepCheckpoint $stepCheckpoint): bool
    {
        // Without --continue the checkpoint is not consulted at all: a fresh run into an
        // existing directory is already refused, so anything still on disk there belongs
        // to a run the user did not ask to resume.
        if (! $installerOption->resume || in_array($step::class, self::ALWAYS_RUN, true)) {
            return false;
        }

        return $stepCheckpoint->completed($step);
    }

    /**
     * @return list<Pipeline>
     */
    private function steps(InstallerConfig $installerConfig, InstallerOption $installerOption, ProcessRunner $processRunner, ?string $transcriptFile = null): array
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
            new SailStart(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder),
            new PhpConstraintApply(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder),
        ];

        if ($installerOption->aroundPackages !== []) {
            $steps[] = new AroundPackagesInstall(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);
        }

        if ($installerOption->qualityTools !== []) {
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

        $steps[] = new AgentGuardConfigure(installerOption: $installerOption, processRunner: $processRunner);

        $steps[] = new AppFinalize(installerOption: $installerOption, processRunner: $processRunner, sailCommandBuilder: $sailCommandBuilder);

        return $steps;
    }

    /**
     * A dry run — or a failure before `laravel new` — leaves the transcript with nowhere
     * to live. Fall back to the working directory rather than losing the lines.
     */
    private function rescueTranscript(?TranscriptLogger $transcriptLogger): void
    {
        if ($transcriptLogger === null || ! $transcriptLogger->hasPendingLines()) {
            return;
        }

        $path = (string) getcwd().'/kalimera-'.date('Ymd-His').'.log';

        $transcriptLogger->useFile($path);

        warning('The application directory does not exist — the transcript was written to '.$path.' instead.');
    }

    private function summarize(InstallerOption $installerOption): void
    {
        $list = fn (array $items): string => $items === [] ? '—' : implode(', ', $items);

        table(
            headers: ['Setting', 'Value'],
            rows: [
                ['Application', $installerOption->appName.'  ('.$installerOption->targetPath.')'],
                ['Starter kit', $installerOption->starterKit],
                ['Inertia (manual)', $installerOption->installInertia ? 'yes' : 'no'],
                ['Ecosystem packages', $list($installerOption->aroundPackages)],
                ['Sail services', $list($installerOption->sailServices)],
                ['PHP constraint', $installerOption->phpConstraint],
                ['Quality tools', $list($installerOption->qualityTools)],
                ['Additional packages', $list($installerOption->additionalPackages)],
                ['Postmark', $installerOption->installPostmark ? 'yes' : 'no'],
                ['Boost agents', $installerOption->installBoost
                    ? ($installerOption->boostAgents === [] ? 'ask during boost:install' : implode(', ', $installerOption->boostAgents))
                    : 'Boost not installed'],
                ['Boost skills repos', $list($installerOption->boostSkillRepos)],
                ['Extra packages', $list($installerOption->extraPackages)],
                ['Extra dev packages', $list($installerOption->extraDevPackages)],
                ['Core structure', $installerOption->coreNamespace === null
                    ? '—'
                    : 'src/{Domain,Shared,Support,Feature,Infrastructure} → '.$installerOption->coreNamespace.'\\'],
            ],
        );
    }

    private function farewell(InstallerOption $installerOption): void
    {
        outro(sprintf(
            'Kalimera, %s! ☀️  Next steps:%s  cd %s%s  ./vendor/bin/sail up -d%s  ./vendor/bin/sail composer quality',
            $installerOption->appName,
            PHP_EOL,
            $installerOption->targetPath,
            PHP_EOL,
            PHP_EOL,
        ));
    }

    private function sunrise(): void
    {
        // The banner is terminal decoration only: piped output, logs and tests stay clean.
        if (! stream_isatty(STDOUT)) {
            return;
        }

        $sun = <<<'SUN'

                     \       |       /
                .     \      |      /     .
                 `.    \     |     /    .'
                   `.    .-~~~~~-.    .'
                     `. .'       `. .'
              ~ ~ ~ ~  (           )  ~ ~ ~ ~
                     .' `.       .' `.
                   .'      ~~~~~      `.
                 .'    /     |     \    `.
                '     /      |      \     '
                     /       |       \

           _  __    _    _     ___ __  __ _____ ____      _
          | |/ /   / \  | |   |_ _|  \/  | ____|  _ \    / \
          | ' /   / _ \ | |    | || |\/| |  _| | |_) |  / _ \
          | . \  / ___ \| |___ | || |  | | |___|  _ <  / ___ \
          |_|\_\/_/   \_\_____|___|_|  |_|_____|_| \_\/_/   \_\

SUN;

        fwrite(STDOUT, "\033[93m".$sun."\033[0m\n");
    }

    private function usage(): void
    {
        fwrite(STDOUT, <<<'USAGE'
        Kalimera — scaffold a Laravel application ready to start building the real projects.

        Usage:
          kalimera new [name] [options]

        Options:
          --dry-run        Print every command without executing anything
          --defaults       Skip all prompts and accept the preselected answers
          --continue       Resume into an existing app directory after a failed run,
                           skipping the steps the previous run already finished
          --config=path    Load the preselected answers and the package catalog from
                           a JSON config (kalimera.config.json in the current directory
                           is picked up automatically)
          --log[=path]     Write a transcript of commands and outcomes to a log file
                           (defaults to kalimera.log inside the new application)
          --verbose, -v    Stream the raw output of every command instead of condensing
                           it to a progress line (the transcript is unaffected)

        USAGE);
    }
}
