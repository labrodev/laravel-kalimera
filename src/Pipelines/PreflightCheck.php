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
    private const string MINIMUM_PHP = '8.3.0';

    private const string MINIMUM_COMPOSER = '2.2.0';

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

        $this->requireVersions();

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

    /**
     * Present is not enough for the two tools that run on the host. `laravel new` installs a
     * framework that requires PHP 8.3, and the package download passes
     * `--ignore-platform-req=ext-*`, a wildcard composer only understands from 2.2. Either
     * one too old fails minutes into the run under an error about something else.
     *
     * Asked through `php -r` so the answer is an exit code a probe can carry; the composer
     * check shells out from there because only its --version output knows the version.
     */
    private function requireVersions(): void
    {
        $minimums = [
            'PHP '.self::MINIMUM_PHP => sprintf('exit(version_compare(PHP_VERSION, "%s", ">=") ? 0 : 1);', self::MINIMUM_PHP),
            'Composer '.self::MINIMUM_COMPOSER => sprintf(
                'preg_match("/(\\d+\\.\\d+\\.\\d+)/", (string) shell_exec("composer --version --no-ansi 2>/dev/null"), $m); exit(isset($m[1]) && version_compare($m[1], "%s", ">=") ? 0 : 1);',
                self::MINIMUM_COMPOSER,
            ),
        ];

        foreach ($minimums as $requirement => $script) {
            if (! $this->processRunner->probe(command: ['php', '-r', $script])) {
                // A rehearsal only prints the plan, and the plan is worth seeing on the
                // machine that is about to be upgraded — the same courtesy a stopped Docker
                // daemon gets below.
                if ($this->processRunner->isDryRun()) {
                    warning($requirement.' or newer is required on the host — fine for a dry run, required for a real install.');

                    continue;
                }

                throw RequirementMissingException::make(
                    reason: 'or newer is required on the host (`php` and `composer` on your PATH). Upgrade it before running kalimera.',
                    requirement: $requirement,
                );
            }
        }
    }
}
