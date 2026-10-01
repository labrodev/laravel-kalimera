<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\ComposerFileEditor;

readonly class PhpConstraintApply implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
    ) {}

    public function label(): string
    {
        return 'Restricting PHP to '.$this->installerOption->phpConstraint;
    }

    /**
     * The platform pin is what lets the host's composer resolve packages for the container:
     * without it composer picks versions for whatever PHP the host happens to run. It stays
     * in the finished application for the same reason — a later `composer update` on the
     * host keeps resolving for the PHP the application actually runs on.
     */
    public function execute(): void
    {
        $version = $this->platformVersion();

        $this->processRunner->applyFileChange(
            action: function () use ($version): void {
                $composerFileEditor = new ComposerFileEditor($this->installerOption->targetPath.'/composer.json');
                $composerFileEditor->setPhpConstraint($this->installerOption->phpConstraint);
                $composerFileEditor->pinPlatformPhp($version);
                $composerFileEditor->save();
            },
            description: sprintf('require php %s and pin composer\'s platform to PHP %s', $this->installerOption->phpConstraint, $version),
        );
    }

    /**
     * The exact PHP the container will run, when the Sail image for this minor is already on
     * disk — the usual case on a machine that has scaffolded before. A bare minor reads as
     * x.y.0 to composer, which refuses any package needing a later patch even though the
     * container has one; the real version does not.
     *
     * Erring low is the only safe direction. A pin above the container's PHP would let
     * composer install a package the container cannot run, failing at runtime instead of
     * at resolution. So an image that is missing, or older than the one `sail up` later
     * builds, simply leaves the pin at or below the truth. `--pull=never` because the image
     * is local by nature (Sail builds it), and a registry round-trip is where a stalled
     * Docker Desktop would hang the run.
     */
    private function platformVersion(): string
    {
        $minor = $this->installerOption->phpMinorVersion();

        $answer = $this->processRunner->ask(command: [
            'docker', 'run', '--rm', '--pull=never', '--entrypoint', 'php',
            'sail-'.$minor.'/app',
            '-r', 'echo PHP_VERSION;',
        ]);

        if ($answer !== null && preg_match('/^'.preg_quote($minor, '/').'\.\d+$/', $answer) === 1) {
            return $answer;
        }

        return $minor;
    }
}
