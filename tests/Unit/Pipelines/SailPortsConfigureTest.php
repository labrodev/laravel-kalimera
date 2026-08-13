<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\SailPortsConfigure;
use Kalimera\Tests\Fakes\FakePortChecker;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeSailPortsConfigure(
    InstallerOption $installerOption,
    FakeProcessRunner $processRunner,
    FakePortChecker $portChecker,
    string $envContents = "APP_NAME=demo\n",
): SailPortsConfigure {
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/.env', $envContents);

    return new SailPortsConfigure(
        installerOption: $installerOption,
        portChecker: $portChecker,
        processRunner: $processRunner,
    );
}

it('writes nothing when all default ports are free', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;

    makeSailPortsConfigure($installerOption, $processRunner, new FakePortChecker)->execute();

    expect($processRunner->fileActions)->toBe([])
        ->and(file_get_contents($installerOption->targetPath.'/.env'))->toBe("APP_NAME=demo\n");
});

it('remaps a busy default port to the next free one', function (): void {
    $installerOption = makeInstallerOption(['sailServices' => []]);
    $processRunner = new FakeProcessRunner;

    makeSailPortsConfigure($installerOption, $processRunner, new FakePortChecker(busy: [80]))->execute();

    expect($processRunner->fileActions)->toBe(['set APP_PORT=81 in .env (default port is busy)'])
        ->and(file_get_contents($installerOption->targetPath.'/.env'))->toBe("APP_NAME=demo\nAPP_PORT=81\n");
});

it('skips fallback ports already claimed by an earlier remapping', function (): void {
    $installerOption = makeInstallerOption(['sailServices' => ['minio']]);
    $processRunner = new FakeProcessRunner;
    $portChecker = new FakePortChecker(busy: range(8900, 9000));

    makeSailPortsConfigure($installerOption, $processRunner, $portChecker)->execute();

    $environment = (string) file_get_contents($installerOption->targetPath.'/.env');

    expect($environment)->toContain('FORWARD_MINIO_PORT=9001')
        ->and($environment)->toContain('FORWARD_MINIO_CONSOLE_PORT=9002');
});

it('leaves a key that is already configured in the env file untouched', function (): void {
    $installerOption = makeInstallerOption(['sailServices' => []]);
    $processRunner = new FakeProcessRunner;

    makeSailPortsConfigure(
        $installerOption,
        $processRunner,
        new FakePortChecker(busy: [80, 8080]),
        "APP_NAME=demo\nAPP_PORT=8080\n",
    )->execute();

    expect($processRunner->fileActions)->toBe([])
        ->and(file_get_contents($installerOption->targetPath.'/.env'))->toBe("APP_NAME=demo\nAPP_PORT=8080\n");
});
