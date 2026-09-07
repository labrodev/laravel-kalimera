<?php

declare(strict_types=1);

use Kalimera\Services\NetworkPortChecker;

/**
 * PHPUnit's error handler surfaces diagnostics even for the `@`-suppressed fsockopen in
 * isBusy(), and a refused connection is the ordinary "port is free" answer rather than
 * anything gone wrong. Swallow it so the expected path does not read as a warning.
 */
function isBusyQuietly(int $port): bool
{
    set_error_handler(static fn (): bool => true);

    try {
        return new NetworkPortChecker()->isBusy($port);
    } finally {
        restore_error_handler();
    }
}

/**
 * @return array{0: resource, 1: int}
 */
function listeningSocket(): array
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);

    if ($server === false) {
        throw new RuntimeException('No local socket could be bound: '.$message);
    }

    $name = (string) stream_socket_get_name($server, false);

    return [$server, (int) substr($name, (int) strrpos($name, ':') + 1)];
}

it('reports a port nobody is listening on as free', function (): void {
    // 1 is privileged and never bound by a user process.
    expect(isBusyQuietly(1))->toBeFalse();
});

it('reports a port with a listener on it as busy', function (): void {
    [$server, $port] = listeningSocket();

    expect(isBusyQuietly($port))->toBeTrue();

    fclose($server);
});

it('reports the same port as free again once the listener goes away', function (): void {
    [$server, $port] = listeningSocket();
    fclose($server);

    // The documented limitation, pinned as behaviour rather than left implicit: isBusy()
    // answers "is something listening right now", so a port reserved by a stopped
    // container reads as free and two projects can be handed the same block. See
    // TROUBLESHOOTING.md, "port is already allocated".
    expect(isBusyQuietly($port))->toBeFalse();
});
