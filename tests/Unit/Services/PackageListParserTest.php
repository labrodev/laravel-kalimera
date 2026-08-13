<?php

declare(strict_types=1);

use Kalimera\Services\PackageListParser;

it('splits a package list on spaces', function (): void {
    expect((new PackageListParser)('vendor/package another/package'))
        ->toBe(['vendor/package', 'another/package']);
});

it('splits a package list on commas', function (): void {
    expect((new PackageListParser)('vendor/package,another/package'))
        ->toBe(['vendor/package', 'another/package']);
});

it('splits a package list on mixed separators with extra whitespace', function (): void {
    expect((new PackageListParser)('  vendor/package, another/package:^2.0   third/package  '))
        ->toBe(['vendor/package', 'another/package:^2.0', 'third/package']);
});

it('turns an empty package answer into an empty list', function (): void {
    expect((new PackageListParser)(''))->toBe([])
        ->and((new PackageListParser)('   '))->toBe([]);
});
