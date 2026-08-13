<?php

declare(strict_types=1);

use Kalimera\Services\TargetResolver;

it('expands the home shorthand against the injected home directory', function (): void {
    $home = tempDir();

    expect(new TargetResolver(home: $home)->resolve('~/my-app'))->toBe(['my-app', $home.'/my-app']);
});

it('resolves the bare home shorthand to the home directory itself', function (): void {
    $home = tempDir();

    expect(new TargetResolver(home: $home)->resolve('~'))->toBe([basename($home), $home]);
});

it('resolves a relative name against the injected working directory', function (): void {
    $cwd = tempDir();

    expect(new TargetResolver(cwd: $cwd)->resolve('my-app'))->toBe(['my-app', $cwd.'/my-app']);
});

it('passes an absolute path through untouched', function (): void {
    $cwd = tempDir();

    expect(new TargetResolver(cwd: $cwd)->resolve('/apps/demo'))->toBe(['demo', '/apps/demo']);
});

it('trims a trailing slash', function (): void {
    $cwd = tempDir();

    expect(new TargetResolver(cwd: $cwd)->resolve('my-app/'))->toBe(['my-app', $cwd.'/my-app']);
});

it('rejects an empty target', function (): void {
    expect(new TargetResolver(cwd: tempDir())->validate(''))
        ->toBe('Enter an application name or a path.');
});

it('rejects a name with invalid characters', function (): void {
    expect(new TargetResolver(cwd: tempDir())->validate('my app!'))
        ->toBe('The application name may only contain letters, numbers, dots, dashes and underscores.');
});

it('rejects a target whose parent directory does not exist', function (): void {
    $cwd = tempDir();

    expect(new TargetResolver(cwd: $cwd)->validate('missing-parent/my-app'))
        ->toBe(sprintf('Parent directory %s does not exist.', $cwd.'/missing-parent'));
});

it('accepts a plain valid name', function (): void {
    expect(new TargetResolver(cwd: tempDir())->validate('my-app'))->toBeNull();
});

it('accepts a valid absolute path', function (): void {
    $cwd = tempDir();

    expect(new TargetResolver(cwd: $cwd)->validate($cwd.'/my-app'))->toBeNull();
});
