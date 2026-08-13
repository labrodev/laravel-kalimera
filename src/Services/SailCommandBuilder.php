<?php

declare(strict_types=1);

namespace Kalimera\Services;

readonly class SailCommandBuilder
{
    public function __construct(private string $appPath) {}

    public function path(): string
    {
        return $this->appPath;
    }

    /**
     * @return list<string>
     */
    public function artisan(string ...$arguments): array
    {
        return array_values([$this->bin(), 'artisan', ...$arguments]);
    }

    /**
     * @return list<string>
     */
    public function composer(string ...$arguments): array
    {
        return array_values([$this->bin(), 'composer', ...$arguments]);
    }

    /**
     * @return list<string>
     */
    public function npm(string ...$arguments): array
    {
        return array_values([$this->bin(), 'npm', ...$arguments]);
    }

    /**
     * @return list<string>
     */
    public function command(string ...$arguments): array
    {
        return array_values([$this->bin(), ...$arguments]);
    }

    private function bin(): string
    {
        return './vendor/bin/sail';
    }
}
