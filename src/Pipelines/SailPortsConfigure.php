<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\PortChecker;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\EnvFileWriter;
use Kalimera\Services\NetworkPortChecker;

use function Laravel\Prompts\info;

class SailPortsConfigure implements Pipeline
{
    private const array APP_PORTS = [['APP_PORT', 80], ['VITE_PORT', 5173]];

    private const array SERVICE_PORTS = [
        'mysql' => [['FORWARD_DB_PORT', 3306]],
        'pgsql' => [['FORWARD_DB_PORT', 5432]],
        'redis' => [['FORWARD_REDIS_PORT', 6379]],
        'mailpit' => [['FORWARD_MAILPIT_PORT', 1025], ['FORWARD_MAILPIT_DASHBOARD_PORT', 8025]],
        'meilisearch' => [['FORWARD_MEILISEARCH_PORT', 7700]],
        'minio' => [['FORWARD_MINIO_PORT', 9000], ['FORWARD_MINIO_CONSOLE_PORT', 8900]],
    ];

    /** @var list<int> */
    private array $claimed = [];

    public function __construct(
        private readonly InstallerOption $installerOption,
        private readonly ProcessRunner $processRunner,
        private readonly PortChecker $portChecker = new NetworkPortChecker,
    ) {}

    public function label(): string
    {
        return 'Resolving host port conflicts';
    }

    public function execute(): void
    {
        $overrides = [];
        $alreadyConfigured = $this->configuredPortKeys();

        foreach ($this->portMap() as [$key, $default]) {
            if (isset($overrides[$key])) {
                continue;
            }

            // A key already present in .env was resolved by a previous run (its port may
            // look busy right now simply because this app's own containers hold it).
            if (in_array($key, $alreadyConfigured, true)) {
                continue;
            }

            if ($this->available($default)) {
                $this->claimed[] = $default;

                continue;
            }

            $overrides[$key] = $this->nextFreePort($default);
        }

        if ($overrides === []) {
            info('All default ports are free on this machine.');

            return;
        }

        foreach ($overrides as $key => $port) {
            $this->processRunner->applyFileChange(
                action: function () use ($key, $port): void {
                    $envFileWriter = new EnvFileWriter($this->installerOption->targetPath.'/.env');
                    $envFileWriter(key: $key, value: (string) $port);
                },
                description: sprintf('set %s=%d in .env (default port is busy)', $key, $port),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function configuredPortKeys(): array
    {
        $environment = @file_get_contents($this->installerOption->targetPath.'/.env');

        if ($environment === false) {
            return [];
        }

        preg_match_all(matches: $matches, pattern: '/^((?:APP_PORT|VITE_PORT|FORWARD_[A-Z_]+_PORT))=/m', subject: $environment);

        return $matches[1];
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function portMap(): array
    {
        $map = self::APP_PORTS;

        foreach ($this->installerOption->sailServices as $service) {
            $map = [...$map, ...self::SERVICE_PORTS[$service] ?? []];
        }

        return $map;
    }

    private function available(int $port): bool
    {
        if (in_array($port, $this->claimed, true)) {
            return false;
        }

        return ! $this->portChecker->isBusy($port);
    }

    private function nextFreePort(int $default): int
    {
        $candidate = $default + 1;

        while (! $this->available($candidate)) {
            $candidate++;
        }

        $this->claimed[] = $candidate;

        return $candidate;
    }
}
