<?php

declare(strict_types=1);

namespace Kalimera\Pipelines;

use Kalimera\Contracts\Pipeline;
use Kalimera\Contracts\ProcessRunner;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Services\SailCommandBuilder;

readonly class PhpConstraintApply implements Pipeline
{
    public function __construct(
        private InstallerOption $installerOption,
        private ProcessRunner $processRunner,
        private SailCommandBuilder $sailCommandBuilder,
    ) {}

    public function label(): string
    {
        return 'Restricting PHP to '.$this->installerOption->phpConstraint;
    }

    public function execute(): void
    {
        // Composer edits its own composer.json inside the container: a host-side write
        // followed by an immediate in-container read can surface an empty file through
        // the macOS VirtioFS mount, and composer silently treats empty as a new project.
        $this->processRunner->runCommand(
            command: $this->sailCommandBuilder->composer(
                'require',
                'php:'.$this->installerOption->phpConstraint,
                '--no-update',
                '--no-interaction',
            ),
            cwd: $this->sailCommandBuilder->path(),
        );
    }
}
