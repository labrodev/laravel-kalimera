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
        return 'Setting up Postmark mail delivery';
    }

    public function execute(): void
    {
        $this->processRunner->runCommand(
            attempts: ProcessRunner::NETWORK_ATTEMPTS,
            // config/mail.php ships a 'postmark' transport and this is the package backing
            // it: Laravel reaches Postmark through symfony/mailer, never through Postmark's
            // own HTTP SDK. That SDK is the wrong dependency twice over — nothing in the
            // scaffold calls an API client, and it caps guzzle at ^7.8 against Laravel 13's
            // 8.x, so requiring it walks guzzle, psr7 and promises back a major version each
            // and drops symfony/polyfill-php82 on the way out. Rewriting six packages of the
            // framework's HTTP stack is what made this step fail on its own -W every run.
            command: $this->sailCommandBuilder->composer('require', 'symfony/postmark-mailer'),
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
