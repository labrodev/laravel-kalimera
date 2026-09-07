<?php

declare(strict_types=1);

namespace Kalimera\Payloads;

readonly class Argument
{
    public function __construct(
        public bool $dryRun,
        public bool $resume,
        public bool $useDefaults,
        public bool $verbose,
        public ?string $command,
        public ?string $configPath,
        public ?string $logPath,
        public ?string $presetName,
    ) {}

    /**
     * @param  list<string>  $argv
     */
    public static function fromArgv(array $argv): self
    {
        $positionals = array_values(array_filter($argv, fn (string $argument): bool => ! str_starts_with($argument, '-')));

        $configPath = null;
        $logPath = null;

        foreach ($argv as $argument) {
            if ($argument === '--log') {
                $logPath = '';
            }

            if (str_starts_with($argument, '--log=')) {
                $logPath = substr($argument, strlen('--log='));
            }

            // A bare --config is kept as '' so the loader can reject it with a clear message.
            if ($argument === '--config') {
                $configPath = '';
            }

            if (str_starts_with($argument, '--config=')) {
                $configPath = substr($argument, strlen('--config='));
            }
        }

        return new self(
            command: $positionals[0] ?? null,
            configPath: $configPath,
            dryRun: in_array('--dry-run', $argv, true),
            logPath: $logPath,
            presetName: $positionals[1] ?? null,
            resume: in_array('--continue', $argv, true),
            useDefaults: in_array('--defaults', $argv, true),
            verbose: in_array('--verbose', $argv, true) || in_array('-v', $argv, true),
        );
    }
}
