<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\note;

readonly class InertiaInstall implements Pipeline
{
    public function __construct(
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Installing Inertia';
    }

    public function execute(): void
    {
        $this->processRunner->runCommand(
            attempts: 3,
            command: $this->sailCommandBuilder->composer('require', 'inertiajs/inertia-laravel'),
            cwd: $this->sailCommandBuilder->path(),
        );

        $this->processRunner->runCommand(
            command: $this->sailCommandBuilder->artisan('inertia:middleware'),
            cwd: $this->sailCommandBuilder->path(),
        );

        note(
            'Inertia still needs manual wiring: register HandleInertiaRequests in bootstrap/app.php, '
            .'create the root Blade view (app.blade.php with @inertia), and install a client-side adapter '
            .'(npm install @inertiajs/react or @inertiajs/vue3).'
        );
    }
}
