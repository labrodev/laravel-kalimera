<?php

declare(strict_types=1);

use Kalimera\Payloads\InstallerOption;
use Kalimera\Pipelines\CoreStructureScaffold;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makeCoreStructureScaffold(InstallerOption $installerOption, FakeProcessRunner $processRunner): CoreStructureScaffold
{
    return new CoreStructureScaffold(
        installerOption: $installerOption,
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $installerOption->targetPath),
    );
}

it('creates the layer directories with .gitkeep files and maps the namespace', function (): void {
    $installerOption = makeInstallerOption();
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $processRunner = new FakeProcessRunner;

    makeCoreStructureScaffold($installerOption, $processRunner)->execute();

    foreach (['Domain', 'Shared', 'Support', 'Feature', 'Infrastructure'] as $layer) {
        expect(file_exists($installerOption->targetPath.'/src/'.$layer.'/.gitkeep'))->toBeTrue();
    }

    $composer = json_decode((string) file_get_contents($installerOption->targetPath.'/composer.json'), true);

    expect($composer['autoload']['psr-4']['Core\\'])->toBe('src/')
        ->and($processRunner->commandLines())->toBe(['./vendor/bin/sail composer dump-autoload']);
});

it('maps a custom namespace to src/', function (): void {
    $installerOption = makeInstallerOption(['coreNamespace' => 'Aviadigit\\Core']);
    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $processRunner = new FakeProcessRunner;

    makeCoreStructureScaffold($installerOption, $processRunner)->execute();

    $composer = json_decode((string) file_get_contents($installerOption->targetPath.'/composer.json'), true);

    expect($composer['autoload']['psr-4']['Aviadigit\\Core\\'])->toBe('src/');
});
