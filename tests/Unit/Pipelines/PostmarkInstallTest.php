<?php

declare(strict_types=1);

use Kalimera\Pipelines\PostmarkInstall;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makePostmarkInstall(string $targetPath, FakeProcessRunner $processRunner): PostmarkInstall
{
    return new PostmarkInstall(
        installerOption: makeInstallerOption(['targetPath' => $targetPath]),
        processRunner: $processRunner,
    );
}

function seedPostmarkApp(string $targetPath): void
{
    mkdir(directory: $targetPath, permissions: 0755, recursive: true);
    file_put_contents($targetPath.'/composer.json', json_encode(['scripts' => ['test' => 'pest']]));
    file_put_contents($targetPath.'/.env', "APP_NAME=demo\n");
    file_put_contents($targetPath.'/.env.example', "APP_NAME=demo\n");
}

it('registers the push and pull scripts', function (): void {
    $targetPath = tempDir().'/demo-app';
    seedPostmarkApp($targetPath);
    $processRunner = new FakeProcessRunner;

    makePostmarkInstall($targetPath, $processRunner)->execute();

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    // The transport itself is downloaded by PackagesRequire; this step only edits files.
    expect($processRunner->commands)->toBe([])
        ->and($manifest['scripts'])->toHaveKeys(['postmark:push', 'postmark:pull'])
        // The existing scripts must survive the edit.
        ->and($manifest['scripts']['test'])->toBe('pest');
});

it('adds the api key to both env files', function (): void {
    $targetPath = tempDir().'/demo-app';
    seedPostmarkApp($targetPath);

    makePostmarkInstall($targetPath, new FakeProcessRunner)->execute();

    expect(file_get_contents($targetPath.'/.env'))->toContain('POSTMARK_API_KEY=')
        ->and(file_get_contents($targetPath.'/.env.example'))->toContain('POSTMARK_API_KEY=');
});

it('touches no files during a dry run', function (): void {
    $targetPath = tempDir().'/demo-app';
    seedPostmarkApp($targetPath);

    makePostmarkInstall($targetPath, new FakeProcessRunner(dryRun: true))->execute();

    expect(file_get_contents($targetPath.'/.env'))->not->toContain('POSTMARK_API_KEY')
        ->and(json_decode((string) file_get_contents($targetPath.'/composer.json'), true)['scripts'])
        ->not->toHaveKey('postmark:push');
});
