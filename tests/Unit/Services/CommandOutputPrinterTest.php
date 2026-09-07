<?php

declare(strict_types=1);

use Kalimera\Services\CommandOutputPrinter;

/**
 * @return array{0: CommandOutputPrinter, 1: resource, 2: resource}
 */
function printerWithStreams(bool $verbose = false, bool $interactive = false, float $heartbeatSeconds = 30.0): array
{
    $stream = fopen('php://memory', 'r+');
    $errorStream = fopen('php://memory', 'r+');

    if ($stream === false || $errorStream === false) {
        throw new RuntimeException('The in-memory streams the printer writes into could not be opened.');
    }

    $commandOutputPrinter = new CommandOutputPrinter(
        verbose: $verbose,
        interactive: $interactive,
        stream: $stream,
        errorStream: $errorStream,
        heartbeatSeconds: $heartbeatSeconds,
    );

    return [$commandOutputPrinter, $stream, $errorStream];
}

/**
 * @param  resource  $handle
 */
function printed($handle): string
{
    rewind($handle);

    return (string) stream_get_contents($handle);
}

it('swallows the output of a successful command', function (): void {
    [$commandOutputPrinter, $output, $errors] = printerWithStreams();

    $commandOutputPrinter->begin();
    $commandOutputPrinter->write("Get:1 http://ports.ubuntu.com noble InRelease\n");
    $commandOutputPrinter->write("Unpacking libicu74:arm64 ...\n");
    $commandOutputPrinter->finish(success: true);

    expect(printed($output))->toBe('')
        ->and(printed($errors))->toBe('');
});

it('replays the tail of a failed command', function (): void {
    [$commandOutputPrinter, $output, $errors] = printerWithStreams();

    $commandOutputPrinter->begin();
    $commandOutputPrinter->write("Reading package lists...\n");
    $commandOutputPrinter->write("E: Unable to locate package php8.5-cli\n");
    $commandOutputPrinter->finish(success: false);

    expect(printed($output))->toBe('')
        ->and(printed($errors))->toContain('E: Unable to locate package php8.5-cli')
        ->and(printed($errors))->toContain('Reading package lists...');
});

it('keeps only the last forty lines of a failed command', function (): void {
    [$commandOutputPrinter, , $errors] = printerWithStreams();

    $commandOutputPrinter->begin();

    foreach (range(1, 100) as $number) {
        $commandOutputPrinter->write('line '.$number.PHP_EOL);
    }

    $commandOutputPrinter->finish(success: false);

    expect(printed($errors))->toContain('Last 40 lines of output')
        ->and(printed($errors))->toContain('line 100')
        ->and(printed($errors))->toContain('line 61')
        ->and(printed($errors))->not->toContain('line 60');
});

it('flushes a trailing line that never ended in a newline', function (): void {
    [$commandOutputPrinter, , $errors] = printerWithStreams();

    $commandOutputPrinter->begin();
    $commandOutputPrinter->write('Killed — out of memory');
    $commandOutputPrinter->finish(success: false);

    expect(printed($errors))->toContain('Killed — out of memory');
});

it('strips ansi escapes and keeps the last segment of a rewritten line', function (): void {
    [$commandOutputPrinter, , $errors] = printerWithStreams();

    $commandOutputPrinter->begin();
    $commandOutputPrinter->write("\033[32m30%\r\033[32m100% done\033[0m\n");
    $commandOutputPrinter->finish(success: false);

    expect(printed($errors))->toContain('│ 100% done')
        ->and(printed($errors))->not->toContain('30%');
});

it('streams everything untouched in verbose mode', function (): void {
    [$commandOutputPrinter, $output, $errors] = printerWithStreams(verbose: true);

    $commandOutputPrinter->begin();
    $commandOutputPrinter->write("Unpacking libicu74:arm64 ...\n");
    $commandOutputPrinter->write("E: broken\n", error: true);
    $commandOutputPrinter->finish(success: true);

    expect(printed($output))->toBe("Unpacking libicu74:arm64 ...\n")
        ->and(printed($errors))->toBe("E: broken\n");
});

it('writes a heartbeat instead of a status line when the output is not a terminal', function (): void {
    [$commandOutputPrinter, $output] = printerWithStreams(heartbeatSeconds: 0.0);

    $commandOutputPrinter->begin();
    $commandOutputPrinter->write("Setting up libgd3:arm64 ...\n");
    $commandOutputPrinter->finish(success: true);

    expect(printed($output))->toContain('Setting up libgd3:arm64 ...')
        ->and(printed($output))->not->toContain("\033[2K");
});

it('paints a rewritten status line on a terminal', function (): void {
    [$commandOutputPrinter, $output] = printerWithStreams(interactive: true, heartbeatSeconds: 0.0);

    $commandOutputPrinter->begin();
    usleep(150_000);
    $commandOutputPrinter->write("Setting up libgd3:arm64 ...\n");
    $commandOutputPrinter->finish(success: true);

    expect(printed($output))->toContain("\r\033[2K")
        ->and(printed($output))->toContain('Setting up libgd3:arm64 ...')
        // The status line is erased so the next step starts on a clean row.
        ->and(printed($output))->toEndWith("\r\033[2K");
});

it('advances the status line on a tick, with no new output', function (): void {
    [$commandOutputPrinter, $output] = printerWithStreams(interactive: true);

    $commandOutputPrinter->begin();
    usleep(150_000);
    $commandOutputPrinter->tick();

    // A child can go quiet for half a minute while a download lands; an indicator that
    // only moves on output stops indicating anything exactly then.
    expect(printed($output))->toContain("\r\033[2K");
});

it('stays silent on a tick in verbose mode', function (): void {
    [$commandOutputPrinter, $output] = printerWithStreams(verbose: true, interactive: true);

    $commandOutputPrinter->begin();
    usleep(150_000);
    $commandOutputPrinter->tick();

    expect(printed($output))->toBe('');
});

it('hands the tail back from finish rather than exposing a getter', function (): void {
    [$commandOutputPrinter] = printerWithStreams();

    $commandOutputPrinter->begin();
    $commandOutputPrinter->write("first\nsecond\n");

    expect($commandOutputPrinter->finish(success: true))->toBe('first'.PHP_EOL.'second');
});

it('strips the escape sequences a bare control-character pass would leave behind', function (string $line, string $expected): void {
    [$commandOutputPrinter, , $errors] = printerWithStreams();

    $commandOutputPrinter->begin();
    $commandOutputPrinter->write($line.PHP_EOL);
    $commandOutputPrinter->finish(success: false);

    expect(printed($errors))->toContain('│ '.$expected);
})->with([
    'charset select' => ["\033(Bnpm warn deprecated", 'npm warn deprecated'],
    'window title' => ["\033]0;building\007Step 5/15", 'Step 5/15'],
    'colour' => ["\033[1;32mDONE\033[0m 12.4s", 'DONE 12.4s'],
]);
