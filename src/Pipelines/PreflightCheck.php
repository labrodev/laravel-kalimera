<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Exceptions\RequirementMissingException;

use function Laravel\Prompts\warning;

use Symfony\Component\Process\ExecutableFinder;

readonly class PreflightCheck implements Pipeline
{
    public function __construct(
        private ProcessRunner $processRunner,
        private ExecutableFinder $executableFinder = new ExecutableFinder,
    ) {}

    public function label(): string
    {
        return 'Checking requirements';
    }

    public function execute(): void
    {
        foreach (['php', 'composer', 'laravel', 'docker', 'git'] as $binary) {
            if ($this->executableFinder->find($binary) === null) {
                throw RequirementMissingException::make(
                    reason: 'was not found in your PATH. Install it before running kalimera.',
                    requirement: sprintf('`%s`', $binary),
                );
            }
        }

        if ($this->processRunner->probe(command: ['docker', 'info'])) {
            return;
        }

        if ($this->processRunner->isDryRun()) {
            warning('Docker daemon is not running — fine for a dry run, required for a real install.');

            return;
        }

        throw RequirementMissingException::make(
            reason: 'did not answer. Start Docker (Docker Desktop, or `sudo systemctl start docker`) '
                .'and make sure your user may reach it — on Linux that means membership of the `docker` group.',
            requirement: 'The Docker daemon',
        );
    }
}
