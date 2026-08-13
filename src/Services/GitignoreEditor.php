<?php

declare(strict_types=1);

namespace Kalimera\Services;

readonly class GitignoreEditor
{
    public function __construct(private string $targetPath) {}

    /**
     * Append the entries that are not listed yet, leaving the existing file untouched
     * so repeated runs never duplicate a line.
     *
     * @param  list<string>  $entries
     */
    public function ensure(array $entries): void
    {
        $path = $this->targetPath.'/.gitignore';
        $contents = file_exists($path) ? (string) file_get_contents($path) : '';

        $listed = array_map(trim(...), explode("\n", $contents));

        foreach ($entries as $entry) {
            if (in_array($entry, $listed, true)) {
                continue;
            }

            $contents = ($contents === '' ? '' : rtrim($contents, "\n")."\n").$entry."\n";
            $listed[] = $entry;
        }

        (new FileWriter)(contents: $contents, path: $path);
    }
}
