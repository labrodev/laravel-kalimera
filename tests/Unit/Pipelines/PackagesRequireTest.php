<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Payloads\AdditionalPackage;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\PackagesRequire;
use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * The command line the host's composer gets: options first, then the names after `--`, so a
 * name that starts with a dash can never be read as an option.
 */
function hostRequire(string $packages, bool $dev = false): string
{
    return 'composer require '.($dev ? '--dev ' : '').'--no-scripts --no-plugins --ignore-platform-req=ext-* --no-interaction -- '.$packages;
}

const CURL_TIMEOUT = 'curl error 28 while downloading https://repo.packagist.org/p2/laravel/horizon.json: Operation timed out after 10001 milliseconds';

const VERSION_CONFLICT = 'Your requirements could not be resolved to an installable set of packages. Problem 1 - laravel/horizon[v5.0.0] require php ^8.6';

/**
 * Stands the step up against a fresh `laravel new` skeleton unless the test wrote its own,
 * since the step reads composer.json to skip what is already required.
 *
 * @param  list<AdditionalPackage>  $catalog
 */
function makePackagesRequire(InstallerOption $installerOption, FakeProcessRunner $processRunner, array $catalog = []): PackagesRequire
{
    if (! file_exists($installerOption->targetPath.'/composer.json')) {
        scaffoldFakeApp($installerOption->targetPath);
    }

    return new PackagesRequire(
        catalog: $catalog,
        installerOption: $installerOption,
        processRunner: $processRunner,
        retryDelaySeconds: 0,
    );
}

/**
 * Nothing optional selected, so a test sees only the packages it asks for.
 *
 * @param  array<string, mixed>  $overrides
 */
function bareInstallerOption(array $overrides = []): InstallerOption
{
    return makeInstallerOption([
        'aroundPackages' => [],
        'qualityTools' => [],
        'installBoost' => false,
        ...$overrides,
    ]);
}

it('requires every selected package on the host, runtime then dev, then each optional one alone', function (): void {
    $installerOption = makeInstallerOption([
        'installPostmark' => true,
        'installInertia' => true,
        'extraPackages' => ['acme/runtime'],
        'extraDevPackages' => ['acme/dev-tool'],
    ]);
    $processRunner = new FakeProcessRunner;

    makePackagesRequire($installerOption, $processRunner)->execute();

    // Scripts and plugins execute package code and extension checks need the container's
    // PHP, so the host only downloads; DependenciesInstall does the rest in the container.
    expect($processRunner->commandLines())->toBe([
        hostRequire('laravel/horizon laravel/fortify laravel/ai laravel/scout symfony/postmark-mailer inertiajs/inertia-laravel'),
        hostRequire('laravel/pint larastan/larastan barryvdh/laravel-ide-helper rector/rector driftingly/rector-laravel laravel/boost laravel/vet', dev: true),
        hostRequire('laravel/nightwatch'),
        hostRequire('acme/runtime'),
        hostRequire('acme/dev-tool', dev: true),
    ])
        // The host's composer, not sail's: the application directory, no container.
        ->and(array_unique(array_column($processRunner->commands, 'cwd')))->toBe([$installerOption->targetPath]);
});

it('splits the additional packages between require and require --dev by the catalog', function (): void {
    $catalog = [
        new AdditionalPackage(label: 'medialibrary', package: 'spatie/laravel-medialibrary'),
        new AdditionalPackage(dev: true, label: 'debugbar', package: 'barryvdh/laravel-debugbar'),
    ];
    $installerOption = bareInstallerOption([
        'additionalPackages' => ['spatie/laravel-medialibrary', 'barryvdh/laravel-debugbar'],
    ]);
    $processRunner = new FakeProcessRunner;

    makePackagesRequire($installerOption, $processRunner, $catalog)->execute();

    expect($processRunner->commandLines())->toBe([
        hostRequire('spatie/laravel-medialibrary'),
        hostRequire('barryvdh/laravel-debugbar', dev: true),
    ]);
});

it('requires a selected package missing from the catalog as a plain dependency', function (): void {
    $processRunner = new FakeProcessRunner;

    makePackagesRequire(bareInstallerOption(['additionalPackages' => ['vendor/forgotten']]), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        hostRequire('vendor/forgotten'),
    ]);
});

it('requires only the dev packages of the chosen quality tools', function (string $tool, string $packages): void {
    $processRunner = new FakeProcessRunner;

    makePackagesRequire(bareInstallerOption(['qualityTools' => [$tool]]), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        hostRequire($packages, dev: true),
    ]);
})->with([
    'pint' => ['pint', 'laravel/pint'],
    'phpstan' => ['phpstan', 'larastan/larastan barryvdh/laravel-ide-helper'],
    'rector' => ['rector', 'rector/rector driftingly/rector-laravel'],
    'vet' => ['vet', 'laravel/vet'],
]);

// Vet's own platform requirement is PHP 8.4; VetInstall warns about the skip.
it('leaves vet out on a php version vet cannot run on', function (): void {
    $processRunner = new FakeProcessRunner;

    makePackagesRequire(bareInstallerOption(['qualityTools' => ['pint', 'vet'], 'phpConstraint' => '^8.3']), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        hostRequire('laravel/pint', dev: true),
    ]);
});

it('runs nothing when no package was selected', function (): void {
    $processRunner = new FakeProcessRunner;

    makePackagesRequire(bareInstallerOption(), $processRunner)->execute();

    expect($processRunner->commands)->toBe([]);
});

// A starter kit can ship one of these (the React kit requires Fortify), and a resumed run
// finds the ones it already got to; requiring them again would only rewrite a constraint.
it('skips packages composer.json already requires', function (): void {
    $installerOption = makeInstallerOption(['aroundPackages' => ['horizon', 'fortify', 'ai'], 'qualityTools' => ['pint'], 'installBoost' => false]);
    scaffoldFakeApp($installerOption->targetPath);
    $manifest = json_decode((string) file_get_contents($installerOption->targetPath.'/composer.json'), true);
    $manifest['require']['laravel/fortify'] = '^1.0';
    $manifest['require-dev']['laravel/pint'] = '^1.0';
    $manifest['require']['laravel/ai'] = '^0.1';
    file_put_contents($installerOption->targetPath.'/composer.json', json_encode($manifest));
    $processRunner = new FakeProcessRunner;

    makePackagesRequire($installerOption, $processRunner)->execute();

    // Every dev package is already there, so there is no require --dev at all.
    expect($processRunner->commandLines())->toBe([
        hostRequire('laravel/horizon'),
    ]);
});

it('retries a require that failed on the network', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'laravel/horizon', times: 1, output: CURL_TIMEOUT);

    makePackagesRequire(bareInstallerOption(['aroundPackages' => ['horizon']]), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe(array_fill(0, 2, hostRequire('laravel/horizon')))
        ->and(promptOutput())->toContain('Composer could not reach the network');
});

it('recognizes the ways composer reports the network', function (string $output): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'laravel/horizon', times: 1, output: $output);

    makePackagesRequire(bareInstallerOption(['aroundPackages' => ['horizon']]), $processRunner)->execute();

    expect($processRunner->commands)->toHaveCount(2);
})->with([
    'curl' => [CURL_TIMEOUT],
    'dns' => ['php_network_getaddresses: getaddrinfo failed: Could not resolve host: repo.packagist.org'],
    'refused' => ['Connection refused'],
    'reset' => ['Connection reset by peer'],
    'download' => ['The "https://repo.packagist.org/packages.json" file could not be downloaded'],
    'stream' => ['failed to open stream: HTTP request failed'],
    'ssl' => ['SSL: Handshake timed out'],
    'unreachable' => ['Network is unreachable'],
]);

// A conflict fails identically however often it is asked; retrying only delays the error.
it('stops at once on a failure that is not the network', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'laravel/horizon', times: 1, output: VERSION_CONFLICT);

    expect(fn () => makePackagesRequire(bareInstallerOption(['aroundPackages' => ['horizon', 'nightwatch']]), $processRunner)->execute())
        ->toThrow(CommandFailedException::class);

    expect($processRunner->commandLines())->toBe([hostRequire('laravel/horizon')]);
});

// Composer's own explanation of a conflict is the only useful part of the failure, and the
// printer erases a command's live output unless it is replayed.
it('shows composer\'s output for a failure it will not retry', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'laravel/horizon', times: 1, output: VERSION_CONFLICT);

    expect(fn () => makePackagesRequire(bareInstallerOption(['aroundPackages' => ['horizon']]), $processRunner)->execute())
        ->toThrow(CommandFailedException::class);

    expect($processRunner->commands[0]['replayTail'])->toBeTrue();
});

it('stops the scaffold when the network stays down', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn(needle: 'laravel/horizon', output: CURL_TIMEOUT);

    expect(fn () => makePackagesRequire(bareInstallerOption(['aroundPackages' => ['horizon', 'nightwatch']]), $processRunner)->execute())
        ->toThrow(CommandFailedException::class);

    // Three attempts, and nothing after them — not even the optional nightwatch. Each one
    // replays composer's output, since whether it is retried is only decided afterwards.
    expect($processRunner->commandLines())->toBe(array_fill(0, 3, hostRequire('laravel/horizon')))
        ->and(array_column($processRunner->commands, 'replayTail'))->toBe([true, true, true]);
});

// The AI SDK and Scout are set up by AroundPackagesInstall (vendor:publish) once the
// containers are up, so a scaffold without them would fail there; they are required, not
// optional, and go in the one runtime batch in the order they were asked for.
it('requires ai and scout in the runtime batch after horizon and fortify', function (): void {
    $processRunner = new FakeProcessRunner;

    makePackagesRequire(bareInstallerOption(['aroundPackages' => ['scout', 'ai', 'fortify', 'horizon']]), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        hostRequire('laravel/horizon laravel/fortify laravel/ai laravel/scout'),
    ]);
});

it('stops the scaffold when laravel/ai cannot be downloaded', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('laravel/ai', output: VERSION_CONFLICT);

    expect(fn () => makePackagesRequire(bareInstallerOption(['aroundPackages' => ['ai', 'nightwatch']]), $processRunner)->execute())
        ->toThrow(CommandFailedException::class);

    expect($processRunner->commandLines())->toBe([hostRequire('laravel/ai')])
        ->and(promptOutput())->not->toContain('could not be installed');
});

it('warns and continues with the rest when an optional package cannot be downloaded', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('laravel/nightwatch', output: CURL_TIMEOUT);

    makePackagesRequire(bareInstallerOption([
        'aroundPackages' => ['nightwatch'],
        'extraPackages' => ['acme/runtime'],
        'extraDevPackages' => ['acme/dev-tool'],
    ]), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        hostRequire('laravel/nightwatch'),
        hostRequire('laravel/nightwatch'),
        hostRequire('laravel/nightwatch'),
        hostRequire('acme/runtime'),
        hostRequire('acme/dev-tool', dev: true),
    ])
        ->and(promptOutput())->toContain('laravel/nightwatch could not be installed — skipping it.');
});

it('warns and continues when an extra dev package cannot be downloaded', function (): void {
    $processRunner = new FakeProcessRunner;
    $processRunner->failOn('acme/broken');

    makePackagesRequire(bareInstallerOption(['extraDevPackages' => ['acme/broken', 'acme/dev-tool']]), $processRunner)->execute();

    expect(array_slice($processRunner->commandLines(), -1))->toBe([hostRequire('acme/dev-tool', dev: true)])
        ->and(promptOutput())->toContain('acme/broken could not be installed — skipping it.');
});

// A rehearsal never reads the application, which does not exist yet, so it announces
// every package the answers ask for.
it('lists every package in a dry run without reading composer.json', function (): void {
    $installerOption = bareInstallerOption(['aroundPackages' => ['fortify']]);
    scaffoldFakeApp($installerOption->targetPath);
    file_put_contents($installerOption->targetPath.'/composer.json', json_encode(['require' => ['laravel/fortify' => '^1.0']]));
    $processRunner = new FakeProcessRunner(dryRun: true);

    makePackagesRequire($installerOption, $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([
        hostRequire('laravel/fortify'),
    ]);
});

// A catalog entry can carry its constraint; composer.json lists the bare name, in whatever
// case the package was first required with.
it('skips a constrained entry whose name composer.json already requires', function (): void {
    $catalog = [new AdditionalPackage(label: 'data', package: 'Spatie/Laravel-Data:^4.0')];
    $installerOption = bareInstallerOption(['additionalPackages' => ['Spatie/Laravel-Data:^4.0', 'spatie/laravel-permission']]);
    scaffoldFakeApp($installerOption->targetPath);
    $manifest = json_decode((string) file_get_contents($installerOption->targetPath.'/composer.json'), true);
    $manifest['require']['spatie/laravel-data'] = '^4.0';
    file_put_contents($installerOption->targetPath.'/composer.json', json_encode($manifest));
    $processRunner = new FakeProcessRunner;

    makePackagesRequire($installerOption, $processRunner, $catalog)->execute();

    expect($processRunner->commandLines())->toBe([hostRequire('spatie/laravel-permission')]);
});

it('requires a constrained entry that is not yet required as given', function (): void {
    $processRunner = new FakeProcessRunner;

    makePackagesRequire(bareInstallerOption(['extraPackages' => ['acme/runtime:^2.0']]), $processRunner)->execute();

    expect($processRunner->commandLines())->toBe([hostRequire('acme/runtime:^2.0')]);
});

it('puts a name that starts with a dash after the end of the options', function (): void {
    $processRunner = new FakeProcessRunner;

    makePackagesRequire(bareInstallerOption(['extraPackages' => ['--working-dir=/tmp']]), $processRunner)->execute();

    expect(array_column($processRunner->commands, 'command'))->toBe([[
        'composer', 'require', '--no-scripts', '--no-plugins', '--ignore-platform-req=ext-*', '--no-interaction', '--', '--working-dir=/tmp',
    ]]);
});

it('names what it does', function (): void {
    expect(makePackagesRequire(bareInstallerOption(), new FakeProcessRunner)->label())->toBe('Downloading packages');
});
