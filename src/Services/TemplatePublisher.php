<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Exceptions\TemplateMissingException;

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

        copy($source, $target);
    }
}
