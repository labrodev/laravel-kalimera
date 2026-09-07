<?php

declare(strict_types=1);

use Kalimera\Pipelines\AppCreate;
use Kalimera\Tests\Fakes\FakeProcessRunner;

it('runs laravel new with the matching starter kit flag', function (): void {
    $kitFlags = ['react' => '--react', 'vue' => '--vue', 'livewire' => '--livewire', 'svelte' => '--svelte'];

    foreach ($kitFlags as $starterKit => $flag) {
        $installerOption = makeInstallerOption(['starterKit' => $starterKit]);
        $processRunner = new FakeProcessRunner;
        $processRunner->onCommand('laravel new', fn () => mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true));

        new AppCreate(installerOption: $installerOption, processRunner: $processRunner)->execute();

        expect($processRunner->commands)->toHaveCount(1)
            ->and($processRunner->commands[0]['command'])
            ->toBe(['laravel', 'new', 'demo-app', '--pest', '--git', '--no-boost', '--no-interaction', $flag])
            ->and($processRunner->commands[0]['cwd'])->toBe(dirname($installerOption->targetPath));
    }
});

it('runs laravel new without a kit flag for the plain skeleton', function (): void {
    $installerOption = makeInstallerOption(['starterKit' => 'none']);
    $processRunner = new FakeProcessRunner;
    $processRunner->onCommand('laravel new', fn () => mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true));

    new AppCreate(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->commands)->toHaveCount(1)
        ->and($processRunner->commands[0]['command'])
        ->toBe(['laravel', 'new', 'demo-app', '--pest', '--git', '--no-boost', '--no-interaction']);
});

it('skips laravel new and normalizes the providers file when the app already exists', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;

    mkdir(directory: $installerOption->targetPath.'/bootstrap', permissions: 0755, recursive: true);
    touch($installerOption->targetPath.'/artisan');
    file_put_contents($installerOption->targetPath.'/bootstrap/providers.php', <<<'PHP'
    <?php

    use App\Providers\AppServiceProvider;
    use App\Providers\HorizonServiceProvider;

    return [
        AppServiceProvider::class,
        HorizonServiceProvider::class,
    ];

    PHP);

    new AppCreate(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->commands)->toBe([])
        ->and($processRunner->fileActions)->toBe([
            'normalize bootstrap/providers.php to inline class names',
            'gitignore the .kalimera.json and .kalimera-steps.json resume state',
            'save the chosen answers to .kalimera.json so --continue can reuse them',
        ])
        ->and(file_get_contents($installerOption->targetPath.'/bootstrap/providers.php'))->toBe(
            "<?php\n\nreturn [\n"
            ."    App\\Providers\\AppServiceProvider::class,\n"
            ."    App\\Providers\\HorizonServiceProvider::class,\n"
            ."];\n",
        );
});

it('gitignores the transcript inside the created application', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;

    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/.gitignore', "/vendor\n");

    new AppCreate(
        installerOption: $installerOption,
        processRunner: $processRunner,
        transcriptFile: 'kalimera.log',
    )->execute();

    expect($processRunner->fileActions)->toContain('gitignore the kalimera.log transcript')
        ->and(file_get_contents($installerOption->targetPath.'/.gitignore'))
        ->toBe("/vendor\n/kalimera.log\n/.kalimera.json\n/.kalimera-steps.json\n");
});

it('gitignores the resume state even when no transcript is written', function (): void {
    $installerOption = makeInstallerOption();
    $processRunner = new FakeProcessRunner;

    mkdir(directory: $installerOption->targetPath, permissions: 0755, recursive: true);
    file_put_contents($installerOption->targetPath.'/.gitignore', "/vendor\n");

    new AppCreate(installerOption: $installerOption, processRunner: $processRunner)->execute();

    expect($processRunner->fileActions)->not->toContain('gitignore the kalimera.log transcript')
        ->and(file_get_contents($installerOption->targetPath.'/.gitignore'))
        ->toBe("/vendor\n/.kalimera.json\n/.kalimera-steps.json\n");
});
