<?php

declare(strict_types=1);

use Kalimera\Exceptions\TemplateMissingException;
use Kalimera\Services\TemplatePublisher;

it('copies a shipped template into the application', function (): void {
    $targetPath = tempDir().'/demo-app';
    mkdir(directory: $targetPath, permissions: 0755, recursive: true);

    new TemplatePublisher($targetPath)(
        destination: 'app/Providers/AgentGuardServiceProvider.php',
        template: 'AgentGuardServiceProvider.php',
    );

    expect(file_get_contents($targetPath.'/app/Providers/AgentGuardServiceProvider.php'))
        ->toContain('class AgentGuardServiceProvider');
});

it('creates the destination directory when it does not exist yet', function (): void {
    $targetPath = tempDir().'/demo-app';

    new TemplatePublisher($targetPath)(destination: 'deeply/nested/pint.json', template: 'pint.json');

    expect(is_dir($targetPath.'/deeply/nested'))->toBeTrue()
        ->and(file_exists($targetPath.'/deeply/nested/pint.json'))->toBeTrue();
});

it('keeps a copy of a destination it is about to overwrite', function (): void {
    $targetPath = tempDir().'/demo-app';
    mkdir(directory: $targetPath, permissions: 0755, recursive: true);
    file_put_contents($targetPath.'/pint.json', '{"preset": "mine"}');

    new TemplatePublisher($targetPath)(destination: 'pint.json', template: 'pint.json');

    expect(file_get_contents($targetPath.'/pint.json.bak'))->toBe('{"preset": "mine"}')
        ->and(file_get_contents($targetPath.'/pint.json'))
        ->toBe(file_get_contents(dirname(__DIR__, 3).'/templates/pint.json'));
});

it('keeps the earlier backup when a second edit has to be preserved too', function (): void {
    $targetPath = tempDir().'/demo-app';
    mkdir(directory: $targetPath, permissions: 0755, recursive: true);
    $publish = fn () => new TemplatePublisher($targetPath)(destination: 'pint.json', template: 'pint.json');

    file_put_contents($targetPath.'/pint.json', '{"preset": "first try"}');
    $publish();

    // The second failure of a run that keeps failing over the same hand-edited file.
    file_put_contents($targetPath.'/pint.json', '{"preset": "second try"}');
    $publish();

    expect(file_get_contents($targetPath.'/pint.json.bak'))->toBe('{"preset": "first try"}')
        ->and(file_get_contents($targetPath.'/pint.json.bak2'))->toBe('{"preset": "second try"}');
});

it('leaves the edit in place rather than overwriting it when the backup cannot be written', function (): void {
    $targetPath = tempDir().'/demo-app';
    mkdir(directory: $targetPath, permissions: 0755, recursive: true);
    file_put_contents($targetPath.'/pint.json', '{"preset": "mine"}');
    chmod($targetPath, 0555);

    // The publisher @-suppresses the failed copy; Pest reports suppressed diagnostics
    // anyway, so the handler is what keeps the expected failure from reading as a defect.
    set_error_handler(fn (): bool => true);

    try {
        new TemplatePublisher($targetPath)(destination: 'pint.json', template: 'pint.json');
    } finally {
        restore_error_handler();
        chmod($targetPath, 0755);
    }

    // Publishing over an edit that could not be backed up would destroy the one thing the
    // backup exists to protect. A template that did not land is one copy away.
    expect(file_get_contents($targetPath.'/pint.json'))->toBe('{"preset": "mine"}')
        ->and(promptOutput())->toContain('could not be backed up');
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores directory permissions');

it('does not litter a backup when the destination already matches the template', function (): void {
    $targetPath = tempDir().'/demo-app';
    mkdir(directory: $targetPath, permissions: 0755, recursive: true);
    copy(dirname(__DIR__, 3).'/templates/pint.json', $targetPath.'/pint.json');

    new TemplatePublisher($targetPath)(destination: 'pint.json', template: 'pint.json');

    expect(file_exists($targetPath.'/pint.json.bak'))->toBeFalse();
});

it('names the template it could not find', function (): void {
    expect(fn () => new TemplatePublisher(tempDir())(destination: 'x.php', template: 'does-not-exist.php'))
        ->toThrow(TemplateMissingException::class, 'does-not-exist.php');
});
