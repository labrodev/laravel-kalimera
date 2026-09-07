<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerOption;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\ConsoleOutput;

function tempDir(): string
{
    $path = sys_get_temp_dir().'/kalimera-tests/'.uniqid();

    mkdir(directory: $path, permissions: 0755, recursive: true);

    return $path;
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
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
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
        'qualityTools' => ['pint', 'phpstan', 'rector'],
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
