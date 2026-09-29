<?php

declare(strict_types=1);

use Kalimera\Contracts\Pipeline;
use Kalimera\InstallPlan;
use Kalimera\KalimeraInstaller;
use Kalimera\Payloads\InstallerConfig;
use Kalimera\Payloads\InstallerOption;
use Kalimera\Tests\Fakes\FakeExecutableFinder;
use Kalimera\Tests\Fakes\FakePortChecker;
use Kalimera\Tests\Fakes\FakeProcessRunner;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\ConsoleOutput;

function tempDir(): string
{
    $path = sys_get_temp_dir().'/kalimera-tests/'.uniqid();

    mkdir(directory: $path, permissions: 0755, recursive: true);

    // Canonical, as TargetResolver makes every target: the system temp directory is itself
    // a symlink on macOS (/var → /private/var).
    return (string) realpath($path);
}

function removeDir(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeInstallerOption(array $overrides = []): InstallerOption
{
    $arguments = array_merge([
        'appName' => 'demo-app',
        'targetPath' => tempDir().'/demo-app',
        'starterKit' => 'react',
        'installInertia' => false,
        'aroundPackages' => ['horizon', 'fortify', 'ai', 'nightwatch'],
        'sailServices' => ['pgsql', 'redis'],
        'phpConstraint' => '^8.5',
        'qualityTools' => ['pint', 'phpstan', 'rector', 'vet'],
        'additionalPackages' => [],
        'installPostmark' => false,
        'installBoost' => true,
        'coreNamespace' => 'Core',
        'boostAgents' => ['claude_code'],
        'boostSkillRepos' => [],
        'extraPackages' => [],
        'extraDevPackages' => [],
        'dryRun' => false,
        'resume' => false,
    ], $overrides);

    return new InstallerOption(...$arguments);
}

/**
 * The buffer Laravel Prompts writes into for the duration of one test. A fresh one per
 * test keeps an assertion from seeing a line an earlier test printed.
 */
function promptBuffer(bool $fresh = false): BufferedConsoleOutput
{
    static $bufferedConsoleOutput = null;

    if ($fresh || ! $bufferedConsoleOutput instanceof BufferedConsoleOutput) {
        $bufferedConsoleOutput = new BufferedConsoleOutput;
    }

    return $bufferedConsoleOutput;
}

/**
 * Everything `info()` and `warning()` have printed so far this test. Non-destructive, so
 * several assertions can read it.
 */
function promptOutput(): string
{
    return promptBuffer()->content();
}

uses()
    ->beforeEach(function (): void {
        Prompt::setOutput(promptBuffer(fresh: true));
    })
    ->afterEach(function (): void {
        Prompt::setOutput(new ConsoleOutput);
        removeDir(sys_get_temp_dir().'/kalimera-tests');
    })
    ->in('Unit', 'Feature', 'Arch');

/**
 * Stands in for what `laravel new` leaves behind — just enough of a skeleton for the
 * steps that read the application to find what they expect.
 */
function scaffoldFakeApp(string $targetPath): void
{
    mkdir(directory: $targetPath.'/bootstrap', permissions: 0755, recursive: true);
    mkdir(directory: $targetPath.'/database', permissions: 0755, recursive: true);

    file_put_contents($targetPath.'/artisan', "<?php\n");
    file_put_contents($targetPath.'/.env', "APP_NAME=Laravel\nDB_CONNECTION=pgsql\nDB_HOST=pgsql\nDB_PORT=5432\n");
    file_put_contents($targetPath.'/.env.example', "APP_NAME=Laravel\n");
    file_put_contents($targetPath.'/bootstrap/providers.php', "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
    file_put_contents($targetPath.'/composer.json', json_encode([
        'require' => ['php' => '^8.4', 'laravel/framework' => '^13.0'],
        'require-dev' => ['laravel/sail' => '^1.0'],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'scripts' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Runs the installer with every collaborator faked. The scoped error handler mutes
 * the @-suppressed warnings (missing .env probes) that Pest would otherwise report.
 *
 * @param  list<string>  $argv
 */
function runFakeInstaller(FakeProcessRunner $processRunner, array $argv): int
{
    $kalimeraInstaller = new KalimeraInstaller(
        executableFinder: new FakeExecutableFinder,
        portChecker: new FakePortChecker,
        processRunner: $processRunner,
    );

    set_error_handler(fn (): bool => true);

    try {
        return $kalimeraInstaller->execute($argv);
    } finally {
        restore_error_handler();
    }
}

/**
 * Every option that adds a step, against a fake application on disk. Pass the path of an
 * earlier call to get the same application back, e.g. as the --continue that follows it.
 */
function everythingSelected(?string $targetPath = null, bool $resume = false): InstallerOption
{
    if ($targetPath === null) {
        $targetPath = tempDir().'/demo-app';
        scaffoldFakeApp($targetPath);
    }

    return makeInstallerOption([
        'resume' => $resume,
        'targetPath' => $targetPath,
        'starterKit' => 'none',
        'installInertia' => true,
        'installPostmark' => true,
        'additionalPackages' => ['spatie/laravel-data'],
        'extraPackages' => ['acme/runtime'],
        'extraDevPackages' => ['acme/dev-tool'],
    ]);
}

/**
 * @return list<Pipeline>
 */
function fullPlan(InstallerOption $installerOption, FakeProcessRunner $processRunner): array
{
    return new InstallPlan(new FakePortChecker)->steps(
        installerConfig: InstallerConfig::builtIn(),
        installerOption: $installerOption,
        processRunner: $processRunner,
    );
}
