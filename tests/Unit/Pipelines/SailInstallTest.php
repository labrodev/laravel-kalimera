<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\SailInstall;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeSailInstallTarget(InstallerOption $installerOption, string $composerJson): void
{
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/composer.json', $composerJson);
}

it('skips installation when a compose file already exists', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;

    makeSailInstallTarget($installerOption, '{}');
    touch($installerOption->targetPath.'/compose.yaml');

    new SailInstall(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->commands)->toBe([])
        ->and($processRunner->fileActions)->toBe([]);
});

it('runs sail:install with the chosen services when the skeleton ships sail', function (): void {
    $installerOption = makeInstallerOption(['sailServices' => ['redis', 'mailpit']]);
    $processRunner = new FakeProcessRunner;

    makeSailInstallTarget($installerOption, '{"require-dev": {"laravel/sail": "^1.0"}}');

    new SailInstall(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->commands)->toHaveCount(1)
        ->and($processRunner->commands[0]['command'])
        ->toBe(['env', 'DOCKER_HOST=unix:///nonexistent/kalimera.sock', 'php', 'artisan', 'sail:install', '--with=redis,mailpit', '--no-interaction'])
        ->and($processRunner->commands[0]['cwd'])->toBe($installerOption->targetPath)
        ->and($processRunner->fileActions)->toBe([]);
});

it('installs the application container only when no services are selected', function (): void {
    $installerOption = makeInstallerOption(['sailServices' => []]);
    $processRunner = new FakeProcessRunner;

    makeSailInstallTarget($installerOption, '{"require-dev": {"laravel/sail": "^1.0"}}');

    new SailInstall(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->commandLines())->toBe(['env DOCKER_HOST=unix:///nonexistent/kalimera.sock php artisan sail:install --with=none --no-interaction'])
        ->and($processRunner->fileActions)->toBe([]);
});

it('requires sail first when the skeleton does not ship it', function (): void {
    $installerOption = makeInstallerOption(['sailServices' => ['redis']]);
    $processRunner = new FakeProcessRunner;

    makeSailInstallTarget($installerOption, '{}');

    new SailInstall(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        'composer require laravel/sail --dev --no-interaction',
        'env DOCKER_HOST=unix:///nonexistent/kalimera.sock php artisan sail:install --with=redis --no-interaction',
    ]);
});

it('removes the sqlite database and syncs the db block to the env example for a database service', function (): void {
    $installerOption = makeInstallerOption(['sailServices' => ['mysql', 'redis']]);
    $processRunner = new FakeProcessRunner;

    makeSailInstallTarget($installerOption, '{"require-dev": {"laravel/sail": "^1.0"}}');
    mkdir(directory: $installerOption->targetPath.'/database', permissions: 0755, recursive: true);
    touch($installerOption->targetPath.'/database/database.sqlite');
    file_put_contents(
        $installerOption->targetPath.'/.env',
        "APP_NAME=demo\nDB_CONNECTION=mysql\nDB_HOST=mysql\nDB_PORT=3306\nDB_DATABASE=demo_app\n",
    );
    file_put_contents(
        $installerOption->targetPath.'/.env.example',
        "APP_NAME=demo\nDB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n# DB_PORT=3306\n# DB_DATABASE=laravel\n",
    );

    new SailInstall(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect(file_exists($installerOption->targetPath.'/database/database.sqlite'))->toBeFalse()
        ->and(file_get_contents($installerOption->targetPath.'/.env.example'))->toBe(
            "APP_NAME=demo\nDB_CONNECTION=mysql\nDB_HOST=mysql\nDB_PORT=3306\nDB_DATABASE=demo_app\n",
        );
});

it('pins the runtime through sail:install when sail ships it', function (): void {
    $installerOption = makeInstallerOption(['phpConstraint' => '^8.4', 'sailServices' => ['redis']]);
    $processRunner = new FakeProcessRunner;

    makeSailInstallTarget($installerOption, '{"require-dev": {"laravel/sail": "^1.0"}}');
    mkdir($installerOption->targetPath.'/vendor/laravel/sail/runtimes/8.4', 0755, true);

    new SailInstall(installerOption: $installerOption, processRunner: $processRunner)->execute();

    // Sail writes the version into both the build context and the image tag.
    expect($processRunner->commands[0]['command'])->toContain('--php=8.4');
});

it('keeps the default runtime with a warning when sail ships none for the chosen version', function (): void {
    $installerOption = makeInstallerOption(['phpConstraint' => '^8.9', 'sailServices' => ['redis']]);
    $processRunner = new FakeProcessRunner;

    makeSailInstallTarget($installerOption, '{"require-dev": {"laravel/sail": "^1.0"}}');
    mkdir($installerOption->targetPath.'/vendor/laravel/sail/runtimes/8.5', 0755, true);

    new SailInstall(installerOption: $installerOption, processRunner: $processRunner)->execute();

    // Sail substitutes the version unchecked, so passing it would only fail at `sail up`.
    expect(implode(' ', $processRunner->commands[0]['command']))->not->toContain('--php=')
        ->and(promptOutput())->toContain('Sail does not ship a PHP 8.9 runtime');
});

it('passes the runtime during a dry run, which has no vendor directory to check', function (): void {
    $installerOption = makeInstallerOption(['sailServices' => ['redis']]);
    $processRunner = new FakeProcessRunner(dryRun: true);

    new SailInstall(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->commands[0]['command'])->toContain('--php=8.5');
});
