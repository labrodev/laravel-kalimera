<?php

declare(strict_types=1);

use Kalimera\Contracts\Pipeline;
use Kalimera\Pipelines\AgentGuardConfigure;
use Kalimera\Pipelines\AppCreate;
use Kalimera\Pipelines\AppFinalize;
use Kalimera\Pipelines\AroundPackagesInstall;
use Kalimera\Pipelines\PhpConstraintApply;
use Kalimera\Pipelines\PreflightCheck;
use Kalimera\Pipelines\SailInstall;
use Kalimera\Pipelines\SailPortsConfigure;
use Kalimera\Pipelines\SailRuntimeConfigure;
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
 * to the vet rule without anyone having to remember to add it to a list.
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
function tracedPlan(): array
{
    $processRunner = new FakeProcessRunner;
    $trace = [];

    // The same muting runFakeInstaller applies: pipelines @-suppress the misses a fake
    // application produces (a sqlite file that was never created), and Pest reports them.
    set_error_handler(fn (): bool => true);

    try {
        foreach (fullPlan(everythingSelected(), $processRunner) as $step) {
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

it('pins the runtime and resolves ports before the containers are built', function (): void {
    $order = planOrder();

    expect(position($order, SailInstall::class))->toBeLessThan(position($order, SailRuntimeConfigure::class), 'sail:install writes the compose file the runtime is pinned in')
        ->and(position($order, SailRuntimeConfigure::class))->toBeLessThan(position($order, SailStart::class), 'the image is built from the pinned runtime')
        ->and(position($order, SailPortsConfigure::class))->toBeLessThan(position($order, SailStart::class), 'containers bind the ports .env names');
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

it('records the vet trust file after every other step that changes dependencies', function (): void {
    $trace = tracedPlan();
    $order = array_column($trace, 'step');
    $vet = position($order, VetInstall::class);

    // Vet's composer plugin fails any install of a package its trust file does not cover,
    // and the file has to describe the finished vendor directory.
    foreach (stepsIssuing($trace, changesDependencies(...)) as $step) {
        if ($step === VetInstall::class) {
            continue;
        }

        expect(position($order, $step))->toBeLessThan($vet, sprintf('%s changes dependencies after vet recorded its trust file', $step));
    }
});

it('registers the agent guard after horizon has had its turn at bootstrap/providers.php', function (): void {
    $order = planOrder();

    // AroundPackagesInstall rewrites the file whole when horizon:install corrupts it.
    expect(position($order, AroundPackagesInstall::class))->toBeLessThan(position($order, AgentGuardConfigure::class));
});
