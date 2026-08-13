<?php

declare(strict_types=1);

use Kalimera\Services\TranscriptLogger;

it('writes timestamped lines for every semantic event in order', function (): void {
    $path = tempDir().'/transcript.log';
    $logger = new TranscriptLogger($path);

    $logger->begin(['new', 'demo-app']);
    $logger->step(index: 2, label: 'Installing Laravel Sail', total: 11);
    $logger->command(cwd: '/apps/demo-app', printable: 'php artisan sail:install');
    $logger->commandFinished(outcome: 'ok', printable: 'php artisan sail:install');
    $logger->quietCommand(printable: 'docker info', success: true);
    $logger->fileAction(description: 'publish pint.json', dryRun: false);
    $logger->outcome('completed successfully');

    $lines = explode(PHP_EOL, trim((string) file_get_contents($path)));

    expect($lines)->toHaveCount(9)
        ->and($lines[0])->toContain('Kalimera transcript')
        ->and($lines[1])->toContain('argv: new demo-app')
        ->and($lines[2])->toContain('captured below')
        ->and($lines[3])->toContain('Step 2/11 — Installing Laravel Sail')
        ->and($lines[4])->toContain('run in /apps/demo-app: php artisan sail:install')
        ->and($lines[5])->toContain('finished [ok]: php artisan sail:install')
        ->and($lines[6])->toContain('quiet [ok]: docker info')
        ->and($lines[7])->toContain('file: publish pint.json')
        ->and($lines[8])->toContain('outcome: completed successfully');

    foreach ($lines as $line) {
        expect($line)->toMatch('/^\[\d{4}-/');
    }
});

it('records commands without a working directory and dry-run file actions distinctly', function (): void {
    $path = tempDir().'/transcript.log';
    $logger = new TranscriptLogger($path);

    $logger->command(cwd: null, printable: 'docker info');
    $logger->commandFinished(outcome: 'dry-run', printable: 'docker info');
    $logger->fileAction(description: 'publish pint.json', dryRun: true);
    $logger->quietCommand(printable: 'php -l providers.php', success: false);

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('run: docker info')
        ->and($contents)->toContain('finished [dry-run]: docker info')
        ->and($contents)->toContain('file: would publish pint.json')
        ->and($contents)->toContain('quiet [failed]: php -l providers.php');
});

it('appends raw output without a timestamp', function (): void {
    $path = tempDir().'/transcript.log';
    $logger = new TranscriptLogger($path);

    $logger->output('chunk one'.PHP_EOL);
    $logger->output('chunk two'.PHP_EOL);

    expect(file_get_contents($path))->toBe('chunk one'.PHP_EOL.'chunk two'.PHP_EOL);
});

it('appends to an existing transcript instead of truncating it', function (): void {
    $path = tempDir().'/transcript.log';

    new TranscriptLogger($path)->outcome('first run');
    new TranscriptLogger($path)->outcome('second run');

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('outcome: first run')
        ->and($contents)->toContain('outcome: second run');
});

it('buffers lines until a destination is chosen', function (): void {
    $path = tempDir().'/transcript.log';
    $logger = new TranscriptLogger;

    $logger->outcome('recorded before the app existed');

    expect($logger->hasPendingLines())->toBeTrue()
        ->and(file_exists($path))->toBeFalse();

    $logger->useFile($path);

    expect($logger->hasPendingLines())->toBeFalse()
        ->and(file_get_contents($path))->toContain('outcome: recorded before the app existed');
});

it('keeps buffering while the destination directory is missing and flushes once it appears', function (): void {
    $targetPath = tempDir().'/demo-app';
    $logger = new TranscriptLogger;

    $logger->useFile($targetPath.'/kalimera.log');
    $logger->step(index: 1, label: 'Creating the Laravel application', total: 2);

    expect($logger->hasPendingLines())->toBeTrue();

    mkdir(directory: $targetPath, permissions: 0755, recursive: true);
    $logger->step(index: 2, label: 'Installing Laravel Sail', total: 2);

    $contents = (string) file_get_contents($targetPath.'/kalimera.log');

    expect($logger->hasPendingLines())->toBeFalse()
        ->and($contents)->toContain('Step 1/2 — Creating the Laravel application')
        ->and($contents)->toContain('Step 2/2 — Installing Laravel Sail');
});
