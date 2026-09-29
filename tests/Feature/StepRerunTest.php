<?php

declare(strict_types=1);

use Kalimera\Contracts\Pipeline;
use Kalimera\Services\RunStateFile;
use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * The checkpoint records whole steps, so a step that fails halfway runs again from its
 * start on --continue. Every step therefore has to leave the application exactly as it
 * found it the second time round — a scripts entry appended twice, a provider registered
 * twice, a config file backed up against itself.
 *
 * Each step gets its own run: the plan executes up to and including it, and then that one
 * step executes again as the resumed run would build it. The application on disk must not
 * change. Only files are compared; commands are expected to repeat, which is the point.
 */

/**
 * Relative path to content hash, for every file under the application.
 *
 * The run state file is left out: it is kalimera's bookkeeping, not the application, and
 * the manifest guard legitimately refreshes its composer.json snapshot at the start of
 * every composer command — including the ones a rerun repeats.
 *
 * @return array<string, string>
 */
function fileTree(string $root): array
{
    $tree = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getFilename() !== RunStateFile::FILENAME) {
            $tree[substr((string) $file->getPathname(), strlen($root) + 1)] = md5_file($file->getPathname()) ?: '';
        }
    }

    ksort($tree);

    return $tree;
}

/**
 * @return list<string>
 */
function planStepNames(): array
{
    return array_map(
        fn (Pipeline $step): string => new ReflectionClass($step)->getShortName(),
        fullPlan(everythingSelected(), new FakeProcessRunner),
    );
}

it('leaves the application unchanged when a step runs a second time', function (int $index, string $name): void {
    $firstRun = everythingSelected();
    $targetPath = $firstRun->targetPath;

    set_error_handler(fn (): bool => true);

    try {
        foreach (array_slice(fullPlan($firstRun, new FakeProcessRunner), 0, $index + 1) as $step) {
            $step->execute();
        }

        $afterFirstRun = fileTree($targetPath);

        fullPlan(everythingSelected(resume: true, targetPath: $targetPath), new FakeProcessRunner)[$index]->execute();
    } finally {
        restore_error_handler();
    }

    expect(fileTree($targetPath))->toBe($afterFirstRun, $name.' changed the application when it ran again');
})->with(function (): array {
    $cases = [];

    foreach (planStepNames() as $index => $name) {
        $cases[$name] = [$index, $name];
    }

    return $cases;
});
