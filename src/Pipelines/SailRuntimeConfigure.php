<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\FileWriter;

use function Laravel\Prompts\warning;

readonly class SailRuntimeConfigure implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
    ) {}

    public function label(): string
    {
        return 'Matching the Sail runtime to PHP '.$this->installerOption->phpMinorVersion();
    }

    public function execute(): void
    {
        $version = $this->installerOption->phpMinorVersion();

        $this->processRunner->applyFileChange(
            action: function () use ($version): void {
                $runtimePath = $this->installerOption->targetPath.'/vendor/laravel/sail/runtimes/'.$version;

                if (! is_dir($runtimePath)) {
                    warning(sprintf('Sail does not ship a PHP %s runtime — keeping the default runtime.', $version));

                    return;
                }

                $composePath = $this->composeFile();

                if ($composePath === null) {
                    warning('No compose file was found — keeping the default Sail runtime.');

                    return;
                }

                $contents = (string) file_get_contents($composePath);

                $contents = (string) preg_replace(
                    pattern: '#runtimes/\d+\.\d+#',
                    replacement: 'runtimes/'.$version,
                    subject: $contents,
                );

                $contents = (string) preg_replace(
                    pattern: '#sail-\d+\.\d+/app#',
                    replacement: 'sail-'.$version.'/app',
                    subject: $contents,
                );

                (new FileWriter)(contents: $contents, path: $composePath);
            },
            description: 'pin the compose file to the PHP '.$version.' Sail runtime',
        );
    }

    private function composeFile(): ?string
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml'] as $candidate) {
            $path = $this->installerOption->targetPath.'/'.$candidate;

            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
