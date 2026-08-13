<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\warning;

readonly class SailStart implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Building and starting the Sail containers';
    }

    public function execute(): void
    {
        try {
            $this->up();
        } catch (CommandFailedException) {
            warning('Sail could not start — removing leftover containers from a previous run and retrying.');
            $this->removeLeftovers();
            $this->up();
        }

        // A dry run never starts the containers, so there is nothing to exec into —
        // and a --continue rehearsal must not touch a live container from an earlier run.
        if ($this->processRunner->isDryRun()) {
            return;
        }

        $this->grantHomeDirectory();
    }

    private function up(): void
    {
        $this->processRunner->runCommand(command: $this->sailCommandBuilder->command('up', '-d', '--wait'), cwd: $this->sailCommandBuilder->path());
    }

    /**
     * Sail remaps the container user to the host UID but leaves /home/sail owned by the
     * image's original user, so composer cannot write its cache and re-downloads every
     * package on every command — slow, and far more exposure to flaky bind-mount writes.
     */
    private function grantHomeDirectory(): void
    {
        $this->processRunner->runCommandQuietly(
            command: ['docker', 'compose', 'exec', '-T', '-u', 'root', 'laravel.test', 'chown', '-R', 'sail', '/home/sail'],
            cwd: $this->sailCommandBuilder->path(),
        );
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
            $this->processRunner->runCommandQuietly(
                command: ['docker', 'rm', '-f', $project.'-'.$service.'-1'],
                cwd: $this->sailCommandBuilder->path(),
            );
        }

        $this->processRunner->runCommandQuietly(
            command: ['docker', 'network', 'rm', $project.'_sail'],
            cwd: $this->sailCommandBuilder->path(),
        );
    }

    private function projectName(): string
    {
        return strtolower((string) preg_replace(
            pattern: '/[^a-zA-Z0-9_-]/',
            replacement: '',
            subject: basename($this->installerOption->targetPath),
        ));
    }
}
