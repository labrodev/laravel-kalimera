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
        if (! $this->installerOption->resume) {
            $this->discardPreviousProject();
        }

        try {
            $this->up();
        } catch (CommandFailedException) {
            warning('Sail could not start — removing leftover containers from a previous run and retrying.');
            $this->removeLeftovers();
            $this->up();
        }

        // Every side effect below goes through attemptQuietly, which a dry run skips on
        // its own — there is no live container to exec into after a rehearsal anyway.
        $this->grantHomeDirectory();
    }

    /**
     * Compose derives its project name from the directory, so an app scaffolded under a
     * name that was used before inherits that run's containers and volumes — including a
     * half-migrated database, which later fails `migrate` with duplicate-relation errors
     * that look nothing like the stale data behind them. A --continue run keeps everything.
     *
     * What gets inherited is not necessarily abandoned, though. This directory was created
     * moments ago, so anything already answering to its project name belongs to something
     * else: an earlier run under the same name, or an application still in use in another
     * directory that happens to share it. `down -v` takes that one's database with it.
     * So the removal is announced with the resources it is about to destroy, and skipped
     * outright when there is nothing to inherit — which is the overwhelmingly common case,
     * and the one where a blind `down -v` bought nothing for its risk.
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
            'Docker already has a project named "%s" — from an earlier run under this name, or from another application sharing it. Removing it, and its data, before starting: %s.',
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
     * Sail remaps the container user to the host UID but leaves /home/sail owned by the
     * image's original user, so composer cannot write its cache and re-downloads every
     * package on every command — slow, and far more exposure to flaky bind-mount writes.
     */
    private function grantHomeDirectory(): void
    {
        $this->processRunner->attemptQuietly(
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

    private function projectName(): string
    {
        return strtolower((string) preg_replace(
            pattern: '/[^a-zA-Z0-9_-]/',
            replacement: '',
            subject: basename($this->installerOption->targetPath),
        ));
    }
}
