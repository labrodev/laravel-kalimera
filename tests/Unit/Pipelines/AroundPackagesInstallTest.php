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

it('installs horizon and leaves a healthy providers file untouched', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon']]);
    $processRunner = new FakeProcessRunner;
    $contents = "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n";
    $path = writeProvidersFile($installerOption, $contents);

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/horizon',
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
        './vendor/bin/sail composer require laravel/fortify',
        './vendor/bin/sail artisan fortify:install',
    ]);
});

// The --continue case: an earlier run required the package and then died before
// fortify:install published anything. Reading composer.json would call that "already
// shipped by the starter kit" and skip the install, so the resumed run would report
// success over an application that has Fortify required but not installed.
it('installs fortify when an earlier run required the package but never installed it', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['fortify']]);
    $processRunner = new FakeProcessRunner;

    writeProvidersFile($installerOption, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
    file_put_contents($installerOption->targetPath.'/composer.json', '{"require": {"laravel/fortify": "^1.0"}}');

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/fortify',
        './vendor/bin/sail artisan fortify:install',
    ]);
});

it('continues with the remaining packages when a soft require fails', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['ai', 'nightwatch']]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('laravel/ai');

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/ai',
        './vendor/bin/sail composer require laravel/ai',
        './vendor/bin/sail composer require laravel/ai',
        './vendor/bin/sail composer require laravel/nightwatch',
    ]);
});

it('recovers when a package require fails once and succeeds on retry', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon']]);
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('laravel/horizon', times: 1);
    writeProvidersFile($installerOption, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");

    makeAroundPackagesInstall($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        './vendor/bin/sail composer require laravel/horizon',
        './vendor/bin/sail composer require laravel/horizon',
        './vendor/bin/sail artisan horizon:install',
    ]);
});
