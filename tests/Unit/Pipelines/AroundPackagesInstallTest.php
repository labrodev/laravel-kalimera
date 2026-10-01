<?php

declare(strict_types=1);

use Kalimera\Exceptions\ProvidersRepairFailedException;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\AroundPackagesInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeAroundPackagesInstall(InstallerOption $installerOption, FakeProcessRunner $processRunner): AroundPackagesInstall
{
    return new AroundPackagesInstall(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

function writeProvidersFile(InstallerOption $installerOption, string $contents): string
{
    $path = $installerOption->targetPath.'/bootstrap/providers.php';

    mkdir(directory: dirname($path), permissions: 0755, recursive: true);
    file_put_contents($path, $contents);

    return $path;
}

it('runs the horizon installer and leaves a healthy providers file untouched', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon']]);
    $processRunner = new FakeProcessRunner;
    $contents = "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n";
    $path = writeProvidersFile($installerOption, $contents);

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan horizon:install',
    ])
        ->and($processRunner->commands[0]['cwd'])->toBe($installerOption->targetPath)
        ->and(file_get_contents($path))->toBe($contents);
});

it('repairs a corrupted providers file from the pre-install snapshot', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon']]);
    $processRunner = new FakeProcessRunner;
    $path = writeProvidersFile($installerOption, <<<'PHP'
    <?php

    return [
        App\Providers\AppServiceProvider::class,
    1::class,
    ];

    PHP);

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect(file_get_contents($path))->toBe(
        "<?php\n\nreturn [\n"
        ."    App\\Providers\\AppServiceProvider::class,\n"
        ."    App\\Providers\\HorizonServiceProvider::class,\n"
        ."];\n",
    );
});

it('deduplicates and sorts the providers when repairing', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon']]);
    $processRunner = new FakeProcessRunner;
    $path = writeProvidersFile($installerOption, <<<'PHP'
    <?php

    return [
        App\Providers\HorizonServiceProvider::class,
        App\Providers\AppServiceProvider::class,
    1::class,
    ];

    PHP);

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect(file_get_contents($path))->toBe(
        "<?php\n\nreturn [\n"
        ."    App\\Providers\\AppServiceProvider::class,\n"
        ."    App\\Providers\\HorizonServiceProvider::class,\n"
        ."];\n",
    );
});

it('throws when the repaired providers file still fails linting', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon']]);
    $processRunner = new FakeProcessRunner;
    $processRunner->quietResult(needle: 'php -l', result: false);
    writeProvidersFile($installerOption, "<?php\n\nreturn [\n1::class,\n];\n");

    expect(fn () => makeAroundPackagesInstall($installerOption, $processRunner)->execute())
        ->toThrow(ProvidersRepairFailedException::class);
});

function publishFortifyConfig(InstallerOption $installerOption): void
{
    $path = $installerOption->targetPath.'/config/fortify.php';

    mkdir(directory: dirname($path), permissions: 0755, recursive: true);
    file_put_contents($path, "<?php\n\nreturn [];\n");
}

it('skips fortify when the starter kit already ships it', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['fortify']]);
    $processRunner = new FakeProcessRunner;

    publishFortifyConfig($installerOption);

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commands)->toBe([]);
});

it('installs fortify when the starter kit does not ship it', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['fortify']]);
    $processRunner = new FakeProcessRunner;

    writeProvidersFile($installerOption, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan fortify:install',
    ]);
});

// The --continue case, and the host-first one: PackagesRequire has already put the package
// in composer.json before this step runs, and fortify:install has not published anything. Reading composer.json would call that "already
// shipped by the starter kit" and skip the install, so the resumed run would report
// success over an application that has Fortify required but not installed.
it('installs fortify when an earlier run required the package but never installed it', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['fortify']]);
    $processRunner = new FakeProcessRunner;

    writeProvidersFile($installerOption, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
    file_put_contents($installerOption->targetPath.'/composer.json', '{"require": {"laravel/fortify": "^1.0"}}');

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan fortify:install',
    ]);
});

// AgentGuardConfigure registers its provider on the host before the containers start, so
// horizon:install runs after it. The repair rebuilds the file from the snapshot taken just
// before the installer, which already holds the guard.
it('keeps the agent guard provider when it repairs a corrupted providers file', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon']]);
    $processRunner = new FakeProcessRunner;
    $path = writeProvidersFile(
        $installerOption,
        "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n    App\\Providers\\AgentGuardServiceProvider::class,\n];\n",
    );
    $processRunner->onCommand('horizon:install', function () use ($path): void {
        file_put_contents($path, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n1::class,\n];\n");
    });

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect(file_get_contents($path))->toBe(
        "<?php\n\nreturn [\n"
        ."    App\\Providers\\AgentGuardServiceProvider::class,\n"
        ."    App\\Providers\\AppServiceProvider::class,\n"
        ."    App\\Providers\\HorizonServiceProvider::class,\n"
        ."];\n",
    );
});

it('sets up every selected package in order and requires none itself', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon', 'fortify', 'ai', 'scout', 'nightwatch']]);
    $processRunner = new FakeProcessRunner;
    writeProvidersFile($installerOption, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    // Every package is downloaded on the host by PackagesRequire; nightwatch needs no setup.
    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail artisan horizon:install',
        './vendor/bin/sail artisan fortify:install',
        './vendor/bin/sail artisan vendor:publish --provider=Laravel\\Ai\\AiServiceProvider --no-interaction',
        './vendor/bin/sail artisan vendor:publish --provider=Laravel\\Scout\\ScoutServiceProvider --no-interaction',
    ]);
});

// By provider, as the package documents: its migration carries no tag to publish it by.
it('publishes the ai config and migrations', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['ai']]);
    $processRunner = new FakeProcessRunner;

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect(array_column($processRunner->commands, 'command'))->toBe([
        ['./vendor/bin/sail', 'artisan', 'vendor:publish', '--provider=Laravel\\Ai\\AiServiceProvider', '--no-interaction'],
    ])
        ->and($processRunner->commands[0]['cwd'])->toBe($installerOption->targetPath)
        ->and($processRunner->fileActions)->toBe([]);
});

function writeEnvFiles(InstallerOption $installerOption): void
{
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/.env', "APP_NAME=demo\n");
    file_put_contents($installerOption->targetPath.'/.env.example', "APP_NAME=demo\n");
}

it('publishes the scout config and sets the driver in both env files', function (array $sailServices, string $driver): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['scout'], 'sailServices' => $sailServices]);
    $processRunner = new FakeProcessRunner;
    writeEnvFiles($installerOption);

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect(array_column($processRunner->commands, 'command'))->toBe([
        ['./vendor/bin/sail', 'artisan', 'vendor:publish', '--provider=Laravel\\Scout\\ScoutServiceProvider', '--no-interaction'],
    ])
        ->and($processRunner->fileActions)->toBe(['set SCOUT_DRIVER='.$driver.' in .env and .env.example'])
        ->and(file_get_contents($installerOption->targetPath.'/.env'))->toContain('SCOUT_DRIVER='.$driver)
        ->and(file_get_contents($installerOption->targetPath.'/.env.example'))->toContain('SCOUT_DRIVER='.$driver);
})->with([
    // Full-text queries against the application's own tables, with nothing else to run.
    'pgsql' => [['pgsql', 'redis'], 'database'],
    'mysql' => [['mysql'], 'database'],
    // Without a database server the collection engine is all there is.
    'no database server' => [['redis'], 'collection'],
]);

it('leaves the env files alone during a dry run', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['scout']]);
    $processRunner = new FakeProcessRunner(dryRun: true);
    writeEnvFiles($installerOption);

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->fileActions)->toBe(['set SCOUT_DRIVER=database in .env and .env.example'])
        ->and(file_get_contents($installerOption->targetPath.'/.env'))->not->toContain('SCOUT_DRIVER');
});

it('names what it does', function (): void {
    expect(makeAroundPackagesInstall(makeInstallerOption(), new FakeProcessRunner)->label())
        ->toBe('Setting up the Laravel ecosystem packages');
});
