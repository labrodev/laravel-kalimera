<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\PortChecker;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Exceptions\PortInUseException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\ComposeProjectName;
use Kalimera\Services\EnvFileWriter;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Services\SailPortMap;

use function Laravel\Prompts\warning;

readonly class SailStart implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
        private PortChecker $portChecker,
    ) {}

    public function label(): string
    {
        return 'Building and starting the Sail containers';
    }

    public function execute(): void
    {
        $this->pinProjectName();

        if (! $this->installerOption->resume) {
            $this->discardPreviousProject();
        }

        try {
            $this->up();
        } catch (CommandFailedException $commandFailedException) {
            warning('Sail could not start — removing leftover containers from a previous run and retrying.');
            $this->removeLeftovers();
            $this->refuseBusyPorts($commandFailedException);
            $this->up();
        }
    }

    /**
     * Pinned before anything asks docker about the project, so the name every probe,
     * cleanup and `sail` command uses is the one compose will file the containers under.
     * Only on a fresh run: a resumed one keeps whatever name its containers already have.
     */
    private function pinProjectName(): void
    {
        $composeProjectName = new ComposeProjectName($this->installerOption->targetPath);

        if ($this->installerOption->resume || $composeProjectName->configured() !== null) {
            return;
        }

        $name = $composeProjectName->unique();

        $this->processRunner->applyFileChange(
            action: fn () => new EnvFileWriter($this->installerOption->targetPath.'/.env')(key: ComposeProjectName::ENV_KEY, value: $name),
            description: sprintf('set %s=%s in .env so no other application shares its containers', ComposeProjectName::ENV_KEY, $name),
        );
    }

    /**
     * The project name is unique to this path, so anything already answering to it was
     * left by an earlier run in this very directory — one the user deleted and started
     * over. Inheriting it would bring a half-migrated database along, which later fails
     * `migrate` with duplicate-relation errors that look nothing like the stale data behind
     * them, so it goes. A --continue run keeps everything.
     *
     * The removal is still announced with the resources it destroys, and skipped outright
     * when there is nothing to inherit — the overwhelmingly common case.
     *
     * Deliberately without --remove-orphans: that would also remove containers absent
     * from this compose file but carrying the project label, which are by definition not
     * ours — and with -v their volumes go too.
     */
    private function discardPreviousProject(): void
    {
        $inherited = $this->inheritedResources();

        if ($inherited === []) {
            return;
        }

        warning(sprintf(
            'Docker already has a project named "%s" — left by an earlier run in this directory. Removing it, and its data, before starting: %s.',
            $this->projectName(),
            implode(', ', $inherited),
        ));

        $this->processRunner->attemptQuietly(
            command: $this->sailCommandBuilder->command('down', '-v'),
            cwd: $this->sailCommandBuilder->path(),
        );
    }

    /**
     * Compose names are deterministic, so what exists can be asked one name at a time —
     * an inspect exits non-zero for anything docker does not hold. Probes rather than
     * quiet attempts: these only ask, which is why a dry run runs them too and can report
     * what a real run would have removed.
     *
     * @return list<string>
     */
    private function inheritedResources(): array
    {
        $project = $this->projectName();
        $inherited = [];

        foreach (['laravel.test', ...$this->installerOption->sailServices] as $service) {
            $container = $project.'-'.$service.'-1';

            if ($this->processRunner->probe(command: ['docker', 'container', 'inspect', $container])) {
                $inherited[] = 'container '.$container;
            }
        }

        // Sail names every service volume `sail-<service>`, and the project prefix makes
        // it `<project>_sail-<service>`. Services without one simply answer no.
        foreach ($this->installerOption->sailServices as $service) {
            $volume = $project.'_sail-'.$service;

            if ($this->processRunner->probe(command: ['docker', 'volume', 'inspect', $volume])) {
                $inherited[] = 'volume '.$volume;
            }
        }

        return $inherited;
    }

    private function up(): void
    {
        $this->processRunner->runCommand(command: $this->sailCommandBuilder->command('up', '-d', '--wait'), cwd: $this->sailCommandBuilder->path());
    }

    /**
     * An interrupted earlier run can leave containers and a network claiming this
     * project's names, which makes `docker compose up` refuse to start. Compose
     * names are deterministic (<project>-<service>-1), so they can be removed
     * without inspecting the docker state.
     */
    private function removeLeftovers(): void
    {
        $project = $this->projectName();

        foreach (['laravel.test', ...$this->installerOption->sailServices] as $service) {
            $this->processRunner->attemptQuietly(
                command: ['docker', 'rm', '-f', $project.'-'.$service.'-1'],
                cwd: $this->sailCommandBuilder->path(),
            );
        }

        $this->processRunner->attemptQuietly(
            command: ['docker', 'network', 'rm', $project.'_sail'],
            cwd: $this->sailCommandBuilder->path(),
        );
    }

    /**
     * With the leftovers gone, a port that is still taken is held by something that is not
     * this application — and a third `up` would fail on it exactly like the first two,
     * under an error that talks about containers. Name the port instead.
     */
    private function refuseBusyPorts(CommandFailedException $commandFailedException): void
    {
        if ($this->processRunner->isDryRun()) {
            return;
        }

        $ports = new SailPortMap($this->installerOption->sailServices)->effective($this->installerOption->targetPath.'/.env');
        $busy = array_filter($ports, $this->portChecker->isBusy(...));

        if ($busy !== []) {
            throw PortInUseException::make($busy, $commandFailedException);
        }
    }

    private function projectName(): string
    {
        return new ComposeProjectName($this->installerOption->targetPath)->resolve($this->installerOption->resume);
    }
}
