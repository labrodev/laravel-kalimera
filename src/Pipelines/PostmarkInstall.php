<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\ComposerFileEditor;
use Kalimera\Services\EnvFileWriter;

readonly class PostmarkInstall implements Pipeline
{
    private const array SCRIPTS = [
        'postmark:push' => 'POSTMARK_SERVER_TOKEN="${POSTMARK_SERVER_TOKEN:-$(grep \'^POSTMARK_API_KEY=\' .env | cut -d= -f2- | tr -d \'"\')}" npm run emails:push',
        'postmark:pull' => 'POSTMARK_SERVER_TOKEN="${POSTMARK_SERVER_TOKEN:-$(grep \'^POSTMARK_API_KEY=\' .env | cut -d= -f2- | tr -d \'"\')}" npm run emails:pull',
    ];

    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
    ) {}

    public function label(): string
    {
        return 'Setting up Postmark mail delivery';
    }

    public function execute(): void
    {
        $this->processRunner->applyFileChange(
            action: function (): void {
                $composerFileEditor = new ComposerFileEditor($this->installerOption->targetPath.'/composer.json');

                foreach (self::SCRIPTS as $name => $script) {
                    $composerFileEditor->addScript(name: $name, script: $script);
                }

                $composerFileEditor->save();
            },
            description: 'add composer scripts: postmark:push, postmark:pull',
        );

        $this->processRunner->applyFileChange(
            action: function (): void {
                foreach (['.env', '.env.example'] as $file) {
                    $envFileWriter = new EnvFileWriter($this->installerOption->targetPath.'/'.$file);
                    $envFileWriter(key: 'POSTMARK_API_KEY', value: '');
                }
            },
            description: 'add POSTMARK_API_KEY to .env and .env.example',
        );
    }
}
