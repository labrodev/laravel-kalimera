<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Exceptions\FileWriteFailedException;

readonly class FileWriter
{
    public function __invoke(string $contents, string $path): void
    {
        $temporary = $path.'.kalimera-tmp';

        if (file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw FileWriteFailedException::make($path);
        }
    }
}
