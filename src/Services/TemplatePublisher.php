<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Exceptions\TemplateMissingException;

use function Laravel\Prompts\warning;

readonly class TemplatePublisher
{
    public function __construct(private string $targetPath) {}

    public function __invoke(string $destination, string $template): void
    {
        $source = dirname(__DIR__, 2).'/templates/'.$template;

        if (! file_exists($source)) {
            throw TemplateMissingException::make($source);
        }

        $target = $this->targetPath.'/'.$destination;
        $directory = dirname($target);

        if (! is_dir($directory)) {
            mkdir(directory: $directory, permissions: 0755, recursive: true);
        }

        if (! $this->preserve(source: $source, target: $target)) {
            return;
        }

        copy($source, $target);
    }

    /**
     * Publishing is a copy, so whatever the destination already holds is about to be
     * lost. On a fresh scaffold that is nothing — none of the shipped templates collide
     * with a file `laravel new` leaves behind. It happens when a step re-runs under
     * --continue, over a config the user edited while working out why the first run
     * failed. Keeping the original beside it costs nothing and turns a silent loss into
     * a line of output.
     *
     * Returns whether publishing may go ahead. A backup that could not be written is the
     * one case where it may not: overwriting anyway would destroy the edit this method
     * exists to protect, and a template that failed to land is the more recoverable of
     * the two outcomes — it is one file copy away, and the warning says which.
     */
    private function preserve(string $source, string $target): bool
    {
        if (! file_exists($target) || file_get_contents($target) === file_get_contents($source)) {
            return true;
        }

        $backup = (new BackupPath)($target);

        if (! @copy($target, $backup)) {
            warning(sprintf(
                '%s differs from the template but could not be backed up — it was left as it is, and the template was not published over it.',
                basename($target),
            ));

            return false;
        }

        warning(sprintf(
            '%s already existed and differed from the template — the original was kept as %s.',
            basename($target),
            basename($backup),
        ));

        return true;
    }
}
