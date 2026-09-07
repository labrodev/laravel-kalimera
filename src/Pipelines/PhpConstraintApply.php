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
        // Let composer make this edit rather than rewriting composer.json on the host:
        // composer owns the manifest between the container's own require commands, and a
        // host-side edit dropped in beside them is one more writer racing for the file
        // that the next step hands straight back to composer.
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
