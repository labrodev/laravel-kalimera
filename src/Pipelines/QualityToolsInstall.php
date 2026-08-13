<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\ComposerFileEditor;
use Kalimera\Services\FileWriter;
use Kalimera\Services\GitignoreEditor;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Services\TemplatePublisher;

readonly class QualityToolsInstall implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Setting up static analysis tools';
    }

    public function execute(): void
    {
        $packages = [];

        if ($this->installerOption->wantsQualityTool('pint')) {
            $packages[] = 'laravel/pint';
        }

        if ($this->installerOption->wantsQualityTool('phpstan')) {
            $packages[] = 'larastan/larastan';
            $packages[] = 'barryvdh/laravel-ide-helper';
        }

        if ($this->installerOption->wantsQualityTool('rector')) {
            $packages[] = 'rector/rector';
            $packages[] = 'driftingly/rector-laravel';
        }

        $this->processRunner->runCommand(
            attempts: 3,
            command: $this->sailCommandBuilder->composer('require', '--dev', ...$packages),
            cwd: $this->sailCommandBuilder->path(),
        );

        $this->publishConfigurations();
        $this->registerComposerScripts();
    }

    private function publishConfigurations(): void
    {
        $templatePublisher = new TemplatePublisher(targetPath: $this->installerOption->targetPath);

        if ($this->installerOption->wantsQualityTool('pint')) {
            $this->processRunner->applyFileChange(
                action: fn () => $templatePublisher(destination: 'pint.json', template: 'pint.json'),
                description: 'publish pint.json',
            );
        }

        if ($this->installerOption->wantsQualityTool('phpstan')) {
            $this->processRunner->applyFileChange(
                action: function () use ($templatePublisher): void {
                    $templatePublisher(destination: 'phpstan.neon.dist', template: 'phpstan.neon.dist');
                    $templatePublisher(destination: 'phpstan-baseline.neon', template: 'phpstan-baseline.neon');
                    $this->stripSrcPathUnlessScaffolded(file: 'phpstan.neon.dist', line: "        - src\n");

                    $skeletonConfig = $this->installerOption->targetPath.'/phpstan.neon';

                    if (file_exists($skeletonConfig)) {
                        unlink($skeletonConfig);
                    }
                },
                description: 'publish phpstan.neon.dist with an empty baseline (replaces the skeleton phpstan.neon)',
            );

            $this->processRunner->applyFileChange(
                action: fn () => $this->ignoreIdeHelperFiles(),
                description: 'gitignore the generated ide-helper files',
            );
        }

        if ($this->installerOption->wantsQualityTool('rector')) {
            $this->processRunner->applyFileChange(
                action: function () use ($templatePublisher): void {
                    $templatePublisher(destination: 'rector.php', template: 'rector.php');
                    $this->stripSrcPathUnlessScaffolded(file: 'rector.php', line: "        __DIR__.'/src',\n");
                },
                description: 'publish rector.php',
            );
        }
    }

    private function stripSrcPathUnlessScaffolded(string $file, string $line): void
    {
        if ($this->installerOption->coreNamespace !== null) {
            return;
        }

        $path = $this->installerOption->targetPath.'/'.$file;
        $contents = (string) file_get_contents($path);

        (new FileWriter)(contents: str_replace(search: $line, replace: '', subject: $contents), path: $path);
    }

    private function ignoreIdeHelperFiles(): void
    {
        new GitignoreEditor($this->installerOption->targetPath)->ensure([
            '_ide_helper.php',
            '_ide_helper_models.php',
            '.phpstorm.meta.php',
        ]);
    }

    private function registerComposerScripts(): void
    {
        $scripts = [];
        $quality = [];

        if ($this->installerOption->wantsQualityTool('pint')) {
            $scripts['pint:dry'] = 'vendor/bin/pint --test';
            $scripts['pint:fix'] = 'vendor/bin/pint';
            $quality[] = '@pint:dry';
        }

        if ($this->installerOption->wantsQualityTool('phpstan')) {
            $scripts['phpstan'] = 'vendor/bin/phpstan analyse';
            $scripts['phpstan-clear'] = 'vendor/bin/phpstan clear-result-cache';
            $scripts['ide-helper'] = [
                '@php artisan ide-helper:generate',
                '@php artisan ide-helper:meta',
                '@php artisan ide-helper:models --nowrite',
            ];
            $quality[] = '@phpstan';
        }

        if ($this->installerOption->wantsQualityTool('rector')) {
            $scripts['rector:dry'] = 'vendor/bin/rector --dry-run';
            $scripts['rector:fix'] = 'vendor/bin/rector process';
            $quality[] = '@rector:dry';
        }

        $scripts['quality'] = $quality;

        $this->processRunner->applyFileChange(
            action: function () use ($scripts): void {
                $composerFileEditor = new ComposerFileEditor($this->installerOption->targetPath.'/composer.json');

                foreach ($scripts as $name => $script) {
                    $composerFileEditor->addScript(name: $name, script: $script);
                }

                $composerFileEditor->save();
            },
            description: 'add composer scripts: '.implode(', ', array_keys($scripts)),
        );
    }
}
