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
        'coreNamespace' => 'Core',
        'boostAgents' => ['claude_code'],
        'boostSkillRepos' => [],
        'extraPackages' => [],
        'extraDevPackages' => [],
        'dryRun' => false,
    ], $overrides);

    return new InstallerOption(...$arguments);
}

uses()
    ->beforeEach(function (): void {
        Prompt::setOutput(new BufferedConsoleOutput);
    })
    ->afterEach(function (): void {
        Prompt::setOutput(new ConsoleOutput);
        removeDir(sys_get_temp_dir().'/kalimera-tests');
    })
    ->in('Unit', 'Feature', 'Arch');
