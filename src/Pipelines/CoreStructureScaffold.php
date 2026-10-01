<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\ComposerFileEditor;
use Kalimera\Services\FileWriter;

readonly class CoreStructureScaffold implements Pipeline
{
    private const array LAYERS = ['Domain', 'Shared', 'Support', 'Feature', 'Infrastructure'];

    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
    ) {}

    public function label(): string
    {
        return 'Scaffolding the '.$this->namespace().' src/ structure';
    }

    public function execute(): void
    {
        $this->processRunner->applyFileChange(
            action: function (): void {
                foreach (self::LAYERS as $layer) {
                    $directory = $this->installerOption->targetPath.'/src/'.$layer;

                    if (! is_dir($directory)) {
                        mkdir(directory: $directory, permissions: 0755, recursive: true);
                    }

                    (new FileWriter)(contents: '', path: $directory.'/.gitkeep');
                }
            },
            description: 'create src/{'.implode(',', self::LAYERS).'} with .gitkeep files',
        );

        $this->processRunner->applyFileChange(
            action: function (): void {
                $composerFileEditor = new ComposerFileEditor($this->installerOption->targetPath.'/composer.json');
                $composerFileEditor->addPsr4(namespace: $this->namespace().'\\', path: 'src/');
                $composerFileEditor->save();
            },
            description: 'map the '.$this->namespace().'\\ namespace to src/ in composer.json',
        );
    }

    private function namespace(): string
    {
        return $this->installerOption->coreNamespace ?? 'Core';
    }
}
