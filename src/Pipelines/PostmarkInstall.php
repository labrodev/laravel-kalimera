<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\ComposerFileEditor;
use Kalimera\Services\EnvFileWriter;
use Kalimera\Services\SailCommandBuilder;

readonly class PostmarkInstall implements Pipeline
{
    private const array SCRIPTS = [
        'postmark:push' => 'POSTMARK_SERVER_TOKEN="${POSTMARK_SERVER_TOKEN:-$(grep \'^POSTMARK_API_KEY=\' .env | cut -d= -f2- | tr -d \'"\')}" npm run emails:push',
        'postmark:pull' => 'POSTMARK_SERVER_TOKEN="${POSTMARK_SERVER_TOKEN:-$(grep \'^POSTMARK_API_KEY=\' .env | cut -d= -f2- | tr -d \'"\')}" npm run emails:pull',
    ];

    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Setting up the Postmark SDK';
    }

    public function execute(): void
    {
        $this->processRunner->runCommand(
            attempts: ProcessRunner::NETWORK_ATTEMPTS,
            // -W is load-bearing, not defensive. Laravel 13 allows guzzle ^7.8.2 || ^8.0, so a
            // fresh app locks the newest — 8.x — while the Postmark SDK still caps at ^7.8.
            // Without it composer refuses to touch a package the partial update did not name
            // and the require dies on an unsatisfiable guzzle. Letting it move guzzle back to
            // 7.x costs nothing: the framework supports that range just as fully, and nothing
            // else in the scaffold asks for 8.
            command: $this->sailCommandBuilder->composer('require', '-W', 'wildbit/postmark-php'),
            cwd: $this->sailCommandBuilder->path(),
        );

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
