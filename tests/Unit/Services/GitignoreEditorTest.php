<?php

declare(strict_types=1);

use Kalimera\Services\GitignoreEditor;

it('appends missing entries and keeps the existing ones', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.gitignore', "/vendor\n/.idea\n");

    new GitignoreEditor($targetPath)->ensure(['/kalimera.log', '/.idea']);

    expect(file_get_contents($targetPath.'/.gitignore'))->toBe("/vendor\n/.idea\n/kalimera.log\n");
});

it('creates the gitignore without a leading blank line when none exists', function (): void {
    $targetPath = tempDir();

    new GitignoreEditor($targetPath)->ensure(['/kalimera.log']);

    expect(file_get_contents($targetPath.'/.gitignore'))->toBe("/kalimera.log\n");
});

it('never duplicates an entry across repeated runs', function (): void {
    $targetPath = tempDir();

    new GitignoreEditor($targetPath)->ensure(['/kalimera.log']);
    new GitignoreEditor($targetPath)->ensure(['/kalimera.log']);

    expect(substr_count((string) file_get_contents($targetPath.'/.gitignore'), '/kalimera.log'))->toBe(1);
});

it('matches whole lines so a similar entry is still added', function (): void {
    $targetPath = tempDir();
    file_put_contents($targetPath.'/.gitignore', "kalimera.log\n");

    new GitignoreEditor($targetPath)->ensure(['/kalimera.log']);

    expect(file_get_contents($targetPath.'/.gitignore'))->toBe("kalimera.log\n/kalimera.log\n");
});
