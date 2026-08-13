<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\FileWriter;
use Kalimera\Services\GitignoreEditor;
use Kalimera\Services\InstallerOptionStore;

use function Laravel\Prompts\info;

readonly class AppCreate implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private ?string $transcriptFile = null,
    ) {}

    public function label(): string
    {
        return 'Creating the Laravel application';
    }

    public function execute(): void
    {
        if (file_exists($this->installerOption->targetPath.'/artisan')) {
            info('Application already exists — skipping `laravel new`.');
            $this->processRunner->applyFileChange(
                action: fn () => $this->normalizeProvidersFile(),
                description: 'normalize bootstrap/providers.php to inline class names',
            );
            $this->ignoreTranscript();
            $this->saveInstallerOption();

            return;
        }

        // No --database flag on purpose: the host has no database server. The app is created
        // with the sqlite default and sail:install rewires .env to the chosen Sail service.
        $command = [
            'laravel',
            'new',
            $this->installerOption->appName,
            '--pest',
            '--git',
            '--no-boost',
            '--no-interaction',
        ];

        $kitFlag = match ($this->installerOption->starterKit) {
            'react' => '--react',
            'vue' => '--vue',
            'livewire' => '--livewire',
            'svelte' => '--svelte',
            default => null,
        };

        if ($kitFlag !== null) {
            $command[] = $kitFlag;
        }

        $this->processRunner->runCommand(command: $command, cwd: dirname($this->installerOption->targetPath));

        $this->processRunner->applyFileChange(
            action: fn () => $this->normalizeProvidersFile(),
            description: 'normalize bootstrap/providers.php to inline class names',
        );
        $this->ignoreTranscript();
        $this->saveInstallerOption();
    }

    private function ignoreTranscript(): void
    {
        if ($this->transcriptFile === null) {
            return;
        }

        $entry = '/'.$this->transcriptFile;

        $this->processRunner->applyFileChange(
            action: fn () => new GitignoreEditor($this->installerOption->targetPath)->ensure([$entry]),
            description: 'gitignore the '.$this->transcriptFile.' transcript',
        );
    }

    private function saveInstallerOption(): void
    {
        $this->processRunner->applyFileChange(
            action: fn () => (new InstallerOptionStore)->save($this->installerOption),
            description: 'save the chosen answers to .kalimera.json so --continue can reuse them',
        );
    }

    private function normalizeProvidersFile(): void
    {
        $path = $this->installerOption->targetPath.'/bootstrap/providers.php';

        if (! file_exists($path)) {
            return;
        }

        $providers = require $path;

        if (! is_array($providers) || $providers === []) {
            return;
        }

        $lines = array_map(
            fn (string $provider): string => '    '.$provider.'::class,',
            array_values(array_filter($providers, is_string(...))),
        );

        (new FileWriter)(contents: "<?php\n\nreturn [\n".implode("\n", $lines)."\n];\n", path: $path);
    }
}
