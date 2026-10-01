<?php

declare(strict_types=1);

use Kalimera\Contracts\Pipeline;
use Kalimera\Pipelines\AgentGuardConfigure;
use Kalimera\Pipelines\AppCreate;
use Kalimera\Pipelines\AppFinalize;
use Kalimera\Pipelines\AroundPackagesInstall;
use Kalimera\Pipelines\DependenciesInstall;
use Kalimera\Pipelines\PackagesRequire;
use Kalimera\Pipelines\PhpConstraintApply;
use Kalimera\Pipelines\PreflightCheck;
use Kalimera\Pipelines\SailInstall;
use Kalimera\Pipelines\SailPortsConfigure;
use Kalimera\Pipelines\SailStart;
use Kalimera\Pipelines\VetInstall;
use Kalimera\Tests\Fakes\FakeProcessRunner;

/**
 * Every rule below is one a comment in the plan or a pipeline already states. Written down
 * here, a reordering that breaks one fails under the rule's name — the dry-run snapshot
 * catches the same change, but only as two long lists that no longer match.
 *
 * Where a rule is about what a step does rather than what it is called, it is checked
 * against what the step actually issues: a new step that runs `composer require` is held
 * to the host-first and vet rules without anyone having to remember to add it to a list.
 */
/**
 * @return list<class-string<Pipeline>>
 */
function planOrder(): array
{
    return array_map(fn (Pipeline $step): string => $step::class, fullPlan(everythingSelected(), new FakeProcessRunner));
}

/**
 * Runs the whole plan against a fake application and records, step by step, the commands
 * each one issued.
 *
 * @return list<array{step: class-string<Pipeline>, commands: list<list<string>>}>
 */
function tracedPlan(?FakeProcessRunner $processRunner = null, ?string $targetPath = null): array
{
    $processRunner ??= new FakeProcessRunner;
    $trace = [];

    // The same muting runFakeInstaller applies: pipelines @-suppress the misses a fake
    // application produces (a sqlite file that was never created), and Pest reports them.
    set_error_handler(fn (): bool => true);

    try {
        foreach (fullPlan(everythingSelected($targetPath), $processRunner) as $step) {
            $before = count($processRunner->commands);

            $step->execute();

            $trace[] = [
                'step' => $step::class,
                'commands' => array_map(
                    fn (array $entry): array => $entry['command'],
                    array_slice($processRunner->commands, $before),
                ),
            ];
        }
    } finally {
        restore_error_handler();
    }

    return $trace;
}

/**
 * @param  list<array{step: class-string<Pipeline>, commands: list<list<string>>}>  $trace
 * @param  Closure(list<string>): bool  $matches
 * @return list<class-string<Pipeline>>
 */
function stepsIssuing(array $trace, Closure $matches): array
{
    $steps = [];

    foreach ($trace as $entry) {
        foreach ($entry['commands'] as $command) {
            if ($matches($command)) {
                $steps[] = $entry['step'];

                break;
            }
        }
    }

    return $steps;
}

/**
 * @param  list<string>  $command
 */
function changesDependencies(array $command): bool
{
    return in_array('composer', $command, true)
        && array_intersect(['require', 'update', 'remove'], $command) !== [];
}

/**
 * @param  list<string>  $command
 */
function runsThroughSail(array $command): bool
{
    return $command[0] === './vendor/bin/sail' || array_slice($command, 0, 2) === ['docker', 'compose'];
}

/**
 * @param  list<string>  $command
 */
function runsApplicationCode(array $command): bool
{
    return $command[0] === './vendor/bin/sail' && in_array($command[1] ?? null, ['artisan', 'php'], true);
}

/**
 * @param  list<class-string<Pipeline>>  $order
 */
function position(array $order, string $step): int
{
    $index = array_search($step, $order, true);

    expect($index)->not->toBeFalse(sprintf('%s is missing from the plan', $step));

    return (int) $index;
}

it('includes every pipeline exactly once when everything is selected', function (): void {
    $order = planOrder();

    $pipelines = array_values(array_filter(
        array_map(
            fn (string $file): string => 'Kalimera\\Pipelines\\'.basename($file, '.php'),
            glob(dirname(__DIR__, 2).'/src/Pipelines/*.php') ?: [],
        ),
        // Checks the host before any answers exist; it is not a step of the plan.
        fn (string $class): bool => $class !== PreflightCheck::class,
    ));

    // The checkpoint keys steps by class, so a class that appeared twice would have its
    // second run skipped as already done.
    expect($order)->toHaveCount(count(array_unique($order)))
        ->and($order)->toEqualCanonicalizing($pipelines);
});

it('creates the application before anything else touches it', function (): void {
    expect(planOrder()[0])->toBe(AppCreate::class);
});

it('finalizes last, so migrate, the quality gate and the commit see every change', function (): void {
    expect(array_slice(planOrder(), -1))->toBe([AppFinalize::class]);
});

it('writes the compose file and resolves ports before the containers are built', function (): void {
    $order = planOrder();

    expect(position($order, SailInstall::class))->toBeLessThan(position($order, SailStart::class), 'the image is built from the runtime sail:install pinned')
        ->and(position($order, SailInstall::class))->toBeLessThan(position($order, SailPortsConfigure::class), 'sail:install writes the .env port keys that get resolved')
        ->and(position($order, SailPortsConfigure::class))->toBeLessThan(position($order, SailStart::class), 'containers bind the ports .env names');
});

// A port probed before the downloads has a minute or more to be taken by another program
// before the containers bind it, so the probe happens as late as it can.
it('resolves the ports immediately before the containers bind them, after the downloads', function (): void {
    $order = planOrder();

    expect(position($order, SailPortsConfigure::class))->toBe(position($order, SailStart::class) - 1)
        ->and(position($order, SailPortsConfigure::class))->toBeGreaterThan(position($order, PackagesRequire::class));
});

it('starts the containers before any step runs a command through them', function (): void {
    $trace = tracedPlan();
    $order = array_column($trace, 'step');
    $sailStart = position($order, SailStart::class);

    foreach (stepsIssuing($trace, runsThroughSail(...)) as $step) {
        expect(position($order, $step))->toBeGreaterThanOrEqual($sailStart, sprintf('%s runs through sail before SailStart brought the containers up', $step));
    }
});

it('applies the PHP constraint before any package is resolved against it', function (): void {
    $trace = tracedPlan();
    $order = array_column($trace, 'step');
    $phpConstraint = position($order, PhpConstraintApply::class);

    foreach (stepsIssuing($trace, changesDependencies(...)) as $step) {
        if ($step === PhpConstraintApply::class) {
            continue;
        }

        expect(position($order, $step))->toBeGreaterThan($phpConstraint, sprintf('%s resolves packages before the PHP constraint is applied', $step));
    }
});

it('downloads every package on the host, before the containers exist', function (): void {
    $trace = tracedPlan();
    $order = array_column($trace, 'step');
    $sailStart = position($order, SailStart::class);

    // Composer inside the container rewrote composer.json across the bind mount, where a
    // reader could catch it empty mid-write. On the host it is the only writer.
    foreach (stepsIssuing($trace, changesDependencies(...)) as $step) {
        expect(position($order, $step))->toBeLessThan($sailStart, sprintf('%s changes dependencies once the containers are up', $step));
    }

    expect(stepsIssuing($trace, fn (array $command): bool => changesDependencies($command) && runsThroughSail($command)))->toBe([])
        ->and(stepsIssuing($trace, changesDependencies(...)))->toBe([PackagesRequire::class]);
});

it('prepares composer.json for vet before the packages are downloaded', function (): void {
    $order = planOrder();

    // The plugin entry and the scripts are file edits of the same composer.json the host's
    // composer is about to rewrite; they go in first so nothing downloads without them.
    expect(position($order, VetInstall::class))->toBeLessThan(position($order, PackagesRequire::class))
        ->and(position($order, PhpConstraintApply::class))->toBeLessThan(position($order, PackagesRequire::class), 'the platform pin decides which versions the host resolves');
});

it('installs in the container right after it starts and before any application code runs', function (): void {
    $trace = tracedPlan();
    $order = array_column($trace, 'step');
    $dependencies = position($order, DependenciesInstall::class);

    // composer install is what runs the plugins and package:discover the host skipped; an
    // artisan command before it would boot an application whose discovery never ran.
    expect($dependencies)->toBe(position($order, SailStart::class) + 1);

    foreach (stepsIssuing($trace, runsApplicationCode(...)) as $step) {
        expect(position($order, $step))->toBeGreaterThanOrEqual($dependencies, sprintf('%s runs application code before DependenciesInstall', $step));
    }
});

it('records the vet trust file after every step that changes dependencies', function (): void {
    $trace = tracedPlan();
    $order = array_column($trace, 'step');
    $recordsTrust = stepsIssuing($trace, fn (array $command): bool => in_array('vendor/bin/vet', $command, true) && in_array('--init', $command, true));

    expect($recordsTrust)->toBe([DependenciesInstall::class]);

    // Vet's composer plugin fails any install of a package its trust file does not cover,
    // and the file has to describe the finished vendor directory.
    foreach (stepsIssuing($trace, changesDependencies(...)) as $step) {
        expect(position($order, $step))->toBeLessThan(position($order, DependenciesInstall::class), sprintf('%s changes dependencies after vet recorded its trust file', $step));
    }
});

// The guard is registered on the host, so horizon:install comes after it. When the installer
// mangles bootstrap/providers.php, AroundPackagesInstall rebuilds the file from what was
// there just before it ran — which already includes the guard.
it('keeps the agent guard registered through horizon\'s turn at bootstrap/providers.php', function (): void {
    $order = planOrder();
    $targetPath = tempDir().'/demo-app';
    scaffoldFakeApp($targetPath);
    $providers = $targetPath.'/bootstrap/providers.php';
    $processRunner = new FakeProcessRunner;
    $processRunner->onCommand('horizon:install', function () use ($providers): void {
        file_put_contents($providers, "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n1::class,\n];\n");
    });

    tracedPlan($processRunner, $targetPath);

    expect(position($order, AgentGuardConfigure::class))->toBeLessThan(position($order, AroundPackagesInstall::class))
        ->and((string) file_get_contents($providers))->toContain('App\\Providers\\AgentGuardServiceProvider::class')
        ->and((string) file_get_contents($providers))->toContain('App\\Providers\\HorizonServiceProvider::class')
        ->and((string) file_get_contents($providers))->not->toContain('1::class');
});

it('sets up the ecosystem packages when any of them needs it', function (array $aroundPackages, bool $included): void {
    $steps = array_map(
        fn (Pipeline $step): string => $step::class,
        fullPlan(makeInstallerOption(['aroundPackages' => $aroundPackages]), new FakeProcessRunner),
    );

    expect(in_array(AroundPackagesInstall::class, $steps, true))->toBe($included);
})->with([
    'only horizon' => [['horizon'], true],
    'only fortify' => [['fortify'], true],
    'only ai' => [['ai'], true],
    'only scout' => [['scout'], true],
    // Nightwatch is downloaded and needs nothing run in the container.
    'only nightwatch' => [['nightwatch'], false],
    'none' => [[], false],
]);
