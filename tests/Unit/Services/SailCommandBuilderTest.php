<?php

declare(strict_types=1);

use Kalimera\Services\SailCommandBuilder;

it('builds artisan commands through the sail binary', function (): void {
    expect(new SailCommandBuilder(appPath: '/apps/demo')->artisan('migrate', '--no-interaction'))
        ->toBe(['./vendor/bin/sail', 'artisan', 'migrate', '--no-interaction']);
});

it('builds composer commands through the sail binary', function (): void {
    expect(new SailCommandBuilder(appPath: '/apps/demo')->composer('require', 'laravel/horizon'))
        ->toBe(['./vendor/bin/sail', 'composer', 'require', 'laravel/horizon']);
});

it('builds npm commands through the sail binary', function (): void {
    expect(new SailCommandBuilder(appPath: '/apps/demo')->npm('install'))
        ->toBe(['./vendor/bin/sail', 'npm', 'install']);
});

it('builds raw sail commands', function (): void {
    expect(new SailCommandBuilder(appPath: '/apps/demo')->command('up', '-d', '--wait'))
        ->toBe(['./vendor/bin/sail', 'up', '-d', '--wait']);
});

it('exposes the application path', function (): void {
    expect(new SailCommandBuilder(appPath: '/apps/demo')->path())->toBe('/apps/demo');
});
