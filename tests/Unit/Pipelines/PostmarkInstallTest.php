<?php

declare(strict_types=1);

use Kalimera\Pipelines\PostmarkInstall;
use Kalimera\Services\SailCommandBuilder;
use Kalimera\Tests\Fakes\FakeProcessRunner;

function makePostmarkInstall(string $targetPath, FakeProcessRunner $processRunner): PostmarkInstall
{
    return new PostmarkInstall(
        installerOption: makeInstallerOption(['targetPath' => $targetPath]),
        processRunner: $processRunner,
        sailCommandBuilder: new SailCommandBuilder(appPath: $targetPath),
    );
}

function seedPostmarkApp(string $targetPath): void
{
    mkdir(directory: $targetPath, permissions: 0755, recursive: true);
    file_put_contents($targetPath.'/composer.json', json_encode(['scripts' => ['test' => 'pest']]));
    file_put_contents($targetPath.'/.env', "APP_NAME=demo\n");
    file_put_contents($targetPath.'/.env.example', "APP_NAME=demo\n");
}

it('requires the mail transport and registers the push and pull scripts', function (): void {
    $targetPath = tempDir().'/demo-app';
    seedPostmarkApp($targetPath);
    $processRunner = new FakeProcessRunner;

    makePostmarkInstall($targetPath, $processRunner)->execute();

    $manifest = json_decode((string) file_get_contents($targetPath.'/composer.json'), true);

    // The transport behind config/mail.php's 'postmark' entry, not Postmark's HTTP SDK:
    // the SDK caps guzzle at ^7.8 and would walk Laravel 13's HTTP stack back a major
    // version to install an API client nothing in the scaffold calls.
    expect($processRunner->commandLines())->toBe(['./vendor/bin/sail composer require symfony/postmark-mailer'])
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
