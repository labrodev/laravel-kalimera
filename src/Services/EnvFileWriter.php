<?php

declare(strict_types=1);

namespace Kalimera\Services;

readonly class EnvFileWriter
{
    public function __construct(private string $path) {}

    public function __invoke(string $key, string $value): void
    {
        if (! file_exists($this->path)) {
            return;
        }

        $contents = (string) file_get_contents($this->path);
        $line = $key.'='.$value;

        $replaced = preg_replace(
            pattern: '/^#?\s*'.preg_quote($key, '/').'=.*$/m',
            replacement: $line,
            subject: $contents,
        );

        if ($replaced === null) {
            return;
        }

        if ($replaced === $contents && ! str_contains($contents, $line)) {
            $replaced = rtrim($contents, "\n")."\n".$line."\n";
        }

        (new FileWriter)(contents: $replaced, path: $this->path);
    }
}
