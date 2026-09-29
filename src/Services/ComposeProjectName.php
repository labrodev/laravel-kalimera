<?php

declare(strict_types=1);

namespace Kalimera\Services;

/**
 * The name docker compose files this application's containers and volumes under.
 *
 * Left to itself compose uses the directory name, so ~/work/api and ~/side/api are one
 * project to docker: the second scaffold inherits the first one's database, and the
 * cleanup that clears an inherited project takes the other application's data with it.
 * A name that carries a hash of the full path cannot collide with anything but an earlier
 * run in this same directory — which is the one thing that is safe to clear.
 *
 * It is written to .env as COMPOSE_PROJECT_NAME, which both compose and Sail read, so
 * every later `sail` command in the application agrees with it without being told.
 */
readonly class ComposeProjectName
{
    public const string ENV_KEY = 'COMPOSE_PROJECT_NAME';

    public function __construct(private string $targetPath) {}

    /**
     * The name compose will actually use: whatever .env pins, and otherwise the name this
     * run is about to pin. A resumed run whose .env predates the key keeps the directory
     * name compose has been using all along — renaming it now would orphan the running
     * containers while they still hold the ports the new ones need.
     */
    public function resolve(bool $resume): string
    {
        return $this->configured() ?? ($resume ? $this->legacy() : $this->unique());
    }

    public function unique(): string
    {
        return $this->legacy().'-'.substr(md5($this->targetPath), 0, 8);
    }

    public function configured(): ?string
    {
        $environment = is_file($this->targetPath.'/.env') ? file_get_contents($this->targetPath.'/.env') : false;

        if ($environment === false) {
            return null;
        }

        if (preg_match(pattern: '/^'.self::ENV_KEY.'=("?)([a-z0-9][a-z0-9_-]*)\1\s*$/m', subject: $environment, matches: $matches) !== 1) {
            return null;
        }

        return $matches[2];
    }

    /**
     * Compose's own default: the directory name, lowercased, with everything outside
     * [a-z0-9_-] dropped.
     */
    private function legacy(): string
    {
        return (string) preg_replace(pattern: '/[^a-z0-9_-]/', replacement: '', subject: strtolower(basename($this->targetPath)));
    }
}
