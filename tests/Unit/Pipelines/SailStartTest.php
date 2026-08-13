<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\SailStart;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeSailStart(InstallerOption $installerOption, FakeProcessRunner $processRunner): SailStart
{
    return new SailStart(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

it('starts the containers with a single command when nothing conflicts', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;

    makeSailStart($installerOption, $processRunner)->execute();

    $quietLines = array_map(
        fn (array $entry): string => implode(' ', $entry['command']),
        $processRunner->quietCommands,
    );

    expect($processRunner->commandLines())->toBe(['./vendor/bin/sail up -d --wait'])
        ->and($quietLines)->toBe(['docker compose exec -T -u root laravel.test chown -R sail /home/sail']);
});

it('skips the container home-directory fixup during a dry run', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner(dryRun: true);

    makeSailStart($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe(['./vendor/bin/sail up -d --wait'])
        ->and($processRunner->quietCommands)->toBe([]);
});

it('removes leftover containers and retries when the first start fails', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('up -d --wait', times: 1);

    makeSailStart($installerOption, $processRunner)->execute();

    $quietLines = array_map(
        fn (array $entry): string => implode(' ', $entry['command']),
        $processRunner->quietCommands,
    );

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail up -d --wait',
        './vendor/bin/sail up -d --wait',
    ])
        ->and($quietLines)->toBe([
            'docker rm -f demo-app-laravel.test-1',
            'docker rm -f demo-app-pgsql-1',
            'docker rm -f demo-app-redis-1',
            'docker network rm demo-app_sail',
            'docker compose exec -T -u root laravel.test chown -R sail /home/sail',
        ]);
});

it('propagates the failure when the retry after cleanup also fails', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('up -d --wait');

    makeSailStart($installerOption, $processRunner)->execute();
})->throws(CommandFailedException::class);
