<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\FileWriter;
use Kalimera\Services\TemplatePublisher;

/**
 * Wires laravel/pao's agent detector into a safety layer: destructive database commands
 * are prohibited whenever an AI agent drives the application, exactly as they already are
 * in production.
 */
readonly class AgentGuardConfigure implements Pipeline
{
    private const string PROVIDER = 'App\\Providers\\AgentGuardServiceProvider';

    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
    ) {}

    public function label(): string
    {
        return 'Guarding destructive commands against AI agents';
    }

    public function execute(): void
    {
        $this->processRunner->applyFileChange(
            action: function (): void {
                new TemplatePublisher(targetPath: $this->installerOption->targetPath)(
                    destination: 'app/Providers/AgentGuardServiceProvider.php',
                    template: 'AgentGuardServiceProvider.php',
                );
            },
            description: 'publish app/Providers/AgentGuardServiceProvider.php',
        );

        $this->processRunner->applyFileChange(
            action: fn () => $this->registerProvider(),
            description: 'register AgentGuardServiceProvider in bootstrap/providers.php',
        );
    }

    /**
     * bootstrap/providers.php is normalized to one inline `Fqcn::class,` per line earlier in
     * the scaffold, so the provider can be appended before the closing bracket verbatim.
     */
    private function registerProvider(): void
    {
        $path = $this->installerOption->targetPath.'/bootstrap/providers.php';
        $contents = @file_get_contents($path);

        if ($contents === false || str_contains($contents, self::PROVIDER)) {
            return;
        }

        $replaced = preg_replace(
            pattern: '/\n\];/',
            replacement: "\n    ".self::PROVIDER."::class,\n];",
            subject: $contents,
            limit: 1,
        );

        if ($replaced === null || $replaced === $contents) {
            return;
        }

        (new FileWriter)(contents: $replaced, path: $path);
    }
}
