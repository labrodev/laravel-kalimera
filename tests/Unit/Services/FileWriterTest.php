<?php

declare(strict_types=1);

use Kalimera\Exceptions\FileWriteFailedException;
use Kalimera\Services\FileWriter;

it('writes the given contents', function (): void {
    $path = tempDir().'/pint.json';

    (new FileWriter)(contents: '{"preset": "laravel"}', path: $path);

    expect(file_get_contents($path))->toBe('{"preset": "laravel"}');
});

it('leaves no temporary residue behind', function (): void {
    $directory = tempDir();

    (new FileWriter)(contents: 'contents', path: $directory.'/file.txt');

    expect(glob($directory.'/*.kalimera-tmp'))->toBe([]);
});

it('overwrites an existing file', function (): void {
    $path = tempDir().'/file.txt';

    file_put_contents($path, 'old');

    (new FileWriter)(contents: 'new', path: $path);

    expect(file_get_contents($path))->toBe('new');
});

it('throws when the directory is not writable', function (): void {
    $directory = tempDir().'/locked';

    mkdir(directory: $directory, permissions: 0555);

    set_error_handler(fn (): bool => true);

    try {
        expect(fn () => (new FileWriter)(contents: 'contents', path: $directory.'/file.txt'))
            ->toThrow(FileWriteFailedException::class);
    } finally {
        restore_error_handler();
        chmod($directory, 0755);
    }
});
