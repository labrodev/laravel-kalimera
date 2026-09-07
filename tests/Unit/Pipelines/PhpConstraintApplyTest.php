<?php

declare(strict_types=1);

use Kalimera\Pipelines\PhpConstraintApply;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

it('pins the php constraint without resolving dependencies', function (): void {
    $installerOption = makeInstallerOption(['phpConstraint' => '^8.5']);
    $processRunner = new FakeProcessRunner;

    new PhpConstraintApply(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    )->execute();

    // --no-update matters: resolving here would fight the constraint the very next step
    // installs against.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require php:^8.5 --no-update --no-interaction',
    ]);
});

it('names the constraint it is applying', function (): void {
    $installerOption = makeInstallerOption(['phpConstraint' => '^8.4']);

    $label = new PhpConstraintApply(
        installerOption: $installerOption,
        processRunner: new FakeProcessRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    )->label();

    expect($label)->toBe('Restricting PHP to ^8.4');
});
