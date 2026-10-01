<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\ComposerFileEditor;

use function Laravel\Prompts\warning;

/**
 * Vet shows the code that composer is about to write into vendor/ and records the versions
 * the project trusts in vet.json. It runs as a composer plugin, so from the moment it is
 * active every install, update and require in the application is gated on that file.
 *
 * This step only prepares composer.json. The package itself is downloaded with the rest
 * (PackagesRequire, with plugins off), and the trust file is recorded in the container
 * before the plugin ever runs (DependenciesInstall).
 */
readonly class VetInstall implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
    ) {}

    public function label(): string
    {
        return 'Preparing the dependency audit';
    }

    public function execute(): void
    {
        if (! $this->installerOption->installsVet()) {
            warning(sprintf(
                'Vet requires PHP 8.4 or newer and this application is pinned to %s — skipping it. Require laravel/vet by hand once the application moves up.',
                $this->installerOption->phpConstraint,
            ));

            return;
        }

        $this->processRunner->applyFileChange(
            action: function (): void {
                $composerFileEditor = new ComposerFileEditor($this->installerOption->targetPath.'/composer.json');
                // Composer refuses an unlisted composer-plugin outright, so the entry has to
                // be there before the first command that runs with plugins on.
                $composerFileEditor->allowPlugin('laravel/vet');
                $composerFileEditor->addScript(name: 'vet', script: 'vendor/bin/vet');
                // Appended, not assigned: QualityToolsInstall wrote whichever of rector:dry,
                // pint:dry and phpstan were chosen, and a resumed run must not add @vet twice.
                $composerFileEditor->appendScript(name: 'quality', entries: ['@vet']);
                $composerFileEditor->save();
            },
            description: 'allow the laravel/vet composer plugin, add the vet script and @vet to quality',
        );
    }
}
