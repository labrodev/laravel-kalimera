<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\PortChecker;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\EnvFileWriter;
use Kalimera\Services\NetworkPortChecker;
use Kalimera\Services\SailPortMap;

use function Laravel\Prompts\info;

class SailPortsConfigure implements Pipeline
{
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

        foreach ($this->portMap()->defaults() as [$key, $default]) {
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
        return array_keys($this->portMap()->configured($this->installerOption->targetPath.'/.env'));
    }

    private function portMap(): SailPortMap
    {
        return new SailPortMap($this->installerOption->sailServices);
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
