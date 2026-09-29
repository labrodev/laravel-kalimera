<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\ComposerFileEditor;
use Kalimera\Services\SailCommandBuilder;

use function Laravel\Prompts\warning;

use Throwable;

/**
 * Vet shows the code that composer is about to write into vendor/ and records the versions
 * the project trusts in vet.json. It runs as a composer plugin, so from here on every
 * install, update and require in the application is gated on that file.
 *
 * Which is why this step is the last one to touch composer: what the trust file describes
 * becomes the application's baseline, and that has to be the vendor directory the whole
 * scaffold produced rather than a half-installed one. Put earlier in the plan, it would
 * also fail every package step after it — a package nobody has read is exactly what it
 * exists to stop.
 */
readonly class VetInstall implements Pipeline
{
    /**
     * Vet's own platform requirement. Laravel runs on less, and kalimera takes any
     * constraint at the PHP prompt, so the two can legitimately disagree.
     */
    private const string MINIMUM_PHP_VERSION = '8.4';

    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Recording the dependency audit trust file';
    }

    public function execute(): void
    {
        // Left unguarded this is a composer platform error two steps from the end of an
        // otherwise finished scaffold, which reads as a broken run rather than as the one
        // choice that cannot have vet — and the application is complete without it.
        if (version_compare($this->installerOption->phpMinorVersion(), self::MINIMUM_PHP_VERSION, '<')) {
            warning(sprintf(
                'Vet requires PHP %s or newer and this application is pinned to %s — skipping it. Everything else is installed; require laravel/vet by hand once the application moves up.',
                self::MINIMUM_PHP_VERSION,
                $this->installerOption->phpConstraint,
            ));

            return;
        }

        $this->allowPlugin();
        $this->requirePackage();
        $this->recordTrustFile();
        $this->registerComposerScripts();
    }

    /**
     * Before the require, never after it: composer aborts on an unlisted composer-plugin
     * with a PluginManager exception rather than a warning it goes on past, leaving the
     * package written into the manifest and missing from vendor/.
     */
    private function allowPlugin(): void
    {
        $this->processRunner->applyFileChange(
            action: function (): void {
                $composerFileEditor = new ComposerFileEditor($this->installerOption->targetPath.'/composer.json');
                $composerFileEditor->allowPlugin('laravel/vet');
                $composerFileEditor->save();
            },
            description: 'allow the laravel/vet composer plugin in composer.json',
        );
    }

    private function requirePackage(): void
    {
        $this->processRunner->runCommand(
            attempts: ProcessRunner::NETWORK_ATTEMPTS,
            command: $this->sailCommandBuilder->composer('require', 'laravel/vet', '--dev'),
            cwd: $this->sailCommandBuilder->path(),
        );
    }

    /**
     * `--init` records every package vendor/ holds, which is the whole point of doing this
     * last: the tree the scaffold just built becomes the baseline, and every change after
     * it has to be read. No release-age floor is set — one holds back releases younger
     * than N days *even when trusted*, so a fresh Laravel would arrive failing its own
     * audit, and clearing that needs a review nothing here can perform. Turning it on is
     * a deliberate act with a recipe of its own; see TROUBLESHOOTING.md.
     *
     * Warns rather than fails: vet declines to record while composer.lock and vendor/
     * disagree, which is a state an earlier composer flake can leave behind. The
     * application is sound either way — vet.json simply does not cover it yet.
     */
    private function recordTrustFile(): void
    {
        try {
            $this->processRunner->runCommand(
                command: $this->sailCommandBuilder->command('php', 'vendor/bin/vet', '--init', '--no-interaction'),
                cwd: $this->sailCommandBuilder->path(),
            );
        } catch (Throwable) {
            warning('Vet could not record the trust file — run `sail composer install` and then `sail composer vet` in a terminal to finish it.');
        }
    }

    private function registerComposerScripts(): void
    {
        $this->processRunner->applyFileChange(
            action: function (): void {
                $composerFileEditor = new ComposerFileEditor($this->installerOption->targetPath.'/composer.json');
                $composerFileEditor->addScript(name: 'vet', script: 'vendor/bin/vet');
                // Appended, not assigned: QualityToolsInstall wrote whichever of
                // rector:dry, pint:dry and phpstan were chosen, and a resumed run must not
                // add @vet twice.
                $composerFileEditor->appendScript(name: 'quality', entries: ['@vet']);
                $composerFileEditor->save();
            },
            description: 'add composer script: vet, and add @vet to quality',
        );
    }
}
