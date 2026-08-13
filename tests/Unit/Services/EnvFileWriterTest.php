<?php

declare(strict_types=1);

use Kalimera\Services\EnvFileWriter;

function envFixture(string $contents): string
{
    $path = tempDir().'/.env';

    file_put_contents($path, $contents);

    return $path;
}

it('replaces an existing key', function (): void {
    $path = envFixture("APP_NAME=demo\nAPP_PORT=80\n");

    (new EnvFileWriter($path))(key: 'APP_PORT', value: '8080');

    expect(file_get_contents($path))->toBe("APP_NAME=demo\nAPP_PORT=8080\n");
});

it('uncomments a commented key', function (): void {
    $path = envFixture("APP_NAME=demo\n# DB_HOST=127.0.0.1\n");

    (new EnvFileWriter($path))(key: 'DB_HOST', value: 'mysql');

    expect(file_get_contents($path))->toBe("APP_NAME=demo\nDB_HOST=mysql\n");
});

it('appends a missing key', function (): void {
    $path = envFixture("APP_NAME=demo\n");

    (new EnvFileWriter($path))(key: 'POSTMARK_API_KEY', value: '');

    expect(file_get_contents($path))->toBe("APP_NAME=demo\nPOSTMARK_API_KEY=\n");
});

it('does nothing when the env file is missing', function (): void {
    $path = tempDir().'/.env';

    (new EnvFileWriter($path))(key: 'APP_PORT', value: '8080');

    expect(file_exists($path))->toBeFalse();
});

it('leaves the file unchanged when the identical line already exists', function (): void {
    $path = envFixture("APP_NAME=demo\nAPP_PORT=8080\n");

    (new EnvFileWriter($path))(key: 'APP_PORT', value: '8080');

    expect(file_get_contents($path))->toBe("APP_NAME=demo\nAPP_PORT=8080\n");
});
