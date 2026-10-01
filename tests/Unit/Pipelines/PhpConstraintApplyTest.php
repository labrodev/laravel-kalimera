<?php

declare(strict_types=1);

use Kalimera\Pipelines\PhpConstraintApply;
use Kalimera\Tests\Fakes\FakeProcessRunner;

it('pins composer\'s platform to the bare minor when the image does not answer', function (): void {
    $targetPath = tempDir().'/demo-app';
    scaffoldFakeApp($targetPath);
    $processRunner = new FakeProcessRunner;

    new PhpConstraintApply(
        installerOption: makeInstallerOption(['phpConstraint' => '^8.5', 'targetPath' => $targetPath]),
        processRunner: $processRunner,
    )->execute();

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    // The platform pin is what makes the host's composer resolve for the container's PHP.
    expect($manifest['require'])->toBe(['php' => '^8.5', 'laravel/framework' => '^13.0'])
        ->and($manifest['config']['platform']['php'])->toBe('8.5')
        ->and($manifest['require-dev'])->toBe(['laravel/sail' => '^1.0'])
        // A file edit on the host: nothing runs, so no composer resolves anything yet.
        ->and($processRunner->commands)->toBe([])
        ->and(array_column($processRunner->askCommands, 'command'))->toBe([
            ['docker', 'run', '--rm', '--pull=never', '--entrypoint', 'php', 'sail-8.5/app', '-r', 'echo PHP_VERSION;'],
        ])
        ->and($processRunner->fileActions)->toBe(['require php ^8.5 and pin composer\'s platform to PHP 8.5']);
});

it('pins the platform to the minor version of a looser constraint', function (): void {
    $targetPath = tempDir().'/demo-app';
    scaffoldFakeApp($targetPath);

    new PhpConstraintApply(
        installerOption: makeInstallerOption(['phpConstraint' => '>=8.3.4', 'targetPath' => $targetPath]),
        processRunner: new FakeProcessRunner,
    )->execute();

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    expect($manifest['require']['php'])->toBe('>=8.3.4')
        ->and($manifest['config']['platform']['php'])->toBe('8.3');
});

// A bare minor reads as x.y.0 to composer, which would refuse a package needing a later
// patch that the container has.
it('pins the exact php the local sail image runs', function (): void {
    $targetPath = tempDir().'/demo-app';
    scaffoldFakeApp($targetPath);
    $processRunner = new FakeProcessRunner;
    $processRunner->answer(needle: 'sail-8.5/app', output: '8.5.11');

    new PhpConstraintApply(
        installerOption: makeInstallerOption(['phpConstraint' => '^8.5', 'targetPath' => $targetPath]),
        processRunner: $processRunner,
    )->execute();

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    expect($manifest['config']['platform']['php'])->toBe('8.5.11')
        // The requirement stays the constraint that was asked for; only the pin is exact.
        ->and($manifest['require']['php'])->toBe('^8.5')
        ->and($processRunner->fileActions)->toBe(['require php ^8.5 and pin composer\'s platform to PHP 8.5.11']);
});

// Erring low is the only safe direction: a pin above the container's PHP would let composer
// install what the container cannot run.
it('falls back to the bare minor when the image answers with something else', function (?string $answer): void {
    $targetPath = tempDir().'/demo-app';
    scaffoldFakeApp($targetPath);
    $processRunner = new FakeProcessRunner;
    $processRunner->answer(needle: 'sail-8.5/app', output: $answer);

    new PhpConstraintApply(
        installerOption: makeInstallerOption(['phpConstraint' => '^8.5', 'targetPath' => $targetPath]),
        processRunner: $processRunner,
    )->execute();

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    expect($manifest['config']['platform']['php'])->toBe('8.5')
        ->and($manifest['require']['php'])->toBe('^8.5')
        ->and($processRunner->fileActions)->toBe(['require php ^8.5 and pin composer\'s platform to PHP 8.5']);
})->with([
    'no image' => [null],
    'garbage' => ['Unable to find image \'sail-8.5/app:latest\' locally'],
    'a different minor' => ['8.4.3'],
    'a longer minor' => ['8.50.1'],
    'a version with a suffix' => ['8.5.11-dev'],
    'empty' => [''],
]);

it('asks the image for the constraint\'s own minor', function (): void {
    $targetPath = tempDir().'/demo-app';
    scaffoldFakeApp($targetPath);
    $processRunner = new FakeProcessRunner;
    $processRunner->answer(needle: 'sail-8.4/app', output: '8.4.16');

    new PhpConstraintApply(
        installerOption: makeInstallerOption(['phpConstraint' => '^8.4', 'targetPath' => $targetPath]),
        processRunner: $processRunner,
    )->execute();

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    expect($manifest['config']['platform']['php'])->toBe('8.4.16')
        ->and($processRunner->askCommands[0]['command'])->toContain('sail-8.4/app');
});

it('touches no files during a dry run', function (): void {
    $targetPath = tempDir().'/demo-app';
    scaffoldFakeApp($targetPath);
    $before = file_get_contents($targetPath.'/composer.json');
    $processRunner = new FakeProcessRunner(dryRun: true);

    new PhpConstraintApply(
        installerOption: makeInstallerOption(['targetPath' => $targetPath]),
        processRunner: $processRunner,
    )->execute();

    expect(file_get_contents($targetPath.'/composer.json'))->toBe($before)
        ->and($processRunner->fileActions)->toHaveCount(1)
        ->and($processRunner->commands)->toBe([])
        // Asking changes nothing, so a rehearsal asks too.
        ->and($processRunner->askCommands)->toHaveCount(1);
});

it('names the constraint it is applying', function (): void {
    $label = new PhpConstraintApply(
        installerOption: makeInstallerOption(['phpConstraint' => '^8.4']),
        processRunner: new FakeProcessRunner,
    )->label();

    expect($label)->toBe('Restricting PHP to ^8.4');
});
