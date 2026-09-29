<?php

declare(strict_types=1);

namespace Kalimera\Services;

/**
 * The host ports a Sail application forwards, by the .env key that moves each one and the
 * port it takes when the key is absent. One list for the step that picks free ports and
 * the step that has to explain why the containers would not start on them.
 */
readonly class SailPortMap
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

    /**
     * @param  list<string>  $sailServices
     */
    public function __construct(private array $sailServices) {}

    /**
     * @return list<array{0: string, 1: int}>
     */
    public function defaults(): array
    {
        $map = self::APP_PORTS;

        foreach ($this->sailServices as $service) {
            $map = [...$map, ...self::SERVICE_PORTS[$service] ?? []];
        }

        return $map;
    }

    /**
     * The ports the containers will bind: the .env value where there is one, the default
     * where there is not.
     *
     * @return array<string, int>
     */
    public function effective(string $envPath): array
    {
        $configured = $this->configured($envPath);
        $ports = [];

        foreach ($this->defaults() as [$key, $default]) {
            $ports[$key] = $configured[$key] ?? $default;
        }

        return $ports;
    }

    /**
     * @return array<string, int>
     */
    public function configured(string $envPath): array
    {
        $environment = is_file($envPath) ? file_get_contents($envPath) : false;

        if ($environment === false) {
            return [];
        }

        preg_match_all(matches: $matches, pattern: '/^((?:APP_PORT|VITE_PORT|FORWARD_[A-Z_]+_PORT))=(\d*)/m', subject: $environment, flags: PREG_SET_ORDER);

        $configured = [];

        foreach ($matches as [, $key, $port]) {
            // A key with no number yet still counts as resolved; compose falls back to the
            // default for it, so that is the port it binds.
            $configured[$key] = $port === '' ? $this->defaultFor($key) : (int) $port;
        }

        return $configured;
    }

    private function defaultFor(string $key): int
    {
        foreach ($this->defaults() as [$candidate, $default]) {
            if ($candidate === $key) {
                return $default;
            }
        }

        return 0;
    }
}
