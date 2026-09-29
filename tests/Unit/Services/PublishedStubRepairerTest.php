<?php

declare(strict_types=1);

use Kalimera\Services\PublishedStubRepairer;

function publishHorizonProvider(string $targetPath): string
{
    $path = $targetPath.'/app/Providers/HorizonServiceProvider.php';
    mkdir(directory: dirname($path), permissions: 0755, recursive: true);

    file_put_contents($path, <<<'PHP'
<?php

class HorizonServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => in_array($user?->email, [
            //
        ], true));
    }
}

PHP);

    return $path;
}

it('gives the horizon gate a typed list phpstan cannot call impossible', function (): void {
    $targetPath = tempDir();
    $path = publishHorizonProvider($targetPath);

    $repaired = new PublishedStubRepairer($targetPath)->repair();

    $contents = (string) file_get_contents($path);

    // The empty array literal is what phpstan proves nothing can ever match; a method with
    // a declared list<string> keeps horizon's "fill this in" default without the proof.
    expect($repaired)->toContain('app/Providers/HorizonServiceProvider.php')
        ->and($contents)->toContain('$this->allowedEmails()')
        ->and($contents)->toContain('@return list<string>')
        ->and($contents)->not->toContain('in_array($user?->email, [');
});

it('casts the env value horizon hands to Str::slug', function (): void {
    $targetPath = tempDir();
    mkdir(directory: $targetPath.'/config', permissions: 0755, recursive: true);
    $path = $targetPath.'/config/horizon.php';
    file_put_contents($path, "<?php\n\nreturn [\n    'prefix' => env(\n        'HORIZON_PREFIX',\n        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'\n    ),\n];\n");

    new PublishedStubRepairer($targetPath)->repair();

    expect((string) file_get_contents($path))->toContain("Str::slug((string) env('APP_NAME', 'laravel'), '_')");
});

it('applies a repair only once', function (): void {
    $targetPath = tempDir();
    $path = publishHorizonProvider($targetPath);

    new PublishedStubRepairer($targetPath)->repair();
    $afterFirst = (string) file_get_contents($path);

    // A resumed run reaches this step again, and a second allowedEmails() would not parse.
    expect(new PublishedStubRepairer($targetPath)->repair())->toBe([])
        ->and((string) file_get_contents($path))->toBe($afterFirst);
});

it('leaves a stub it does not recognise alone', function (): void {
    $targetPath = tempDir();
    mkdir(directory: $targetPath.'/config', permissions: 0755, recursive: true);
    $path = $targetPath.'/config/horizon.php';
    $rewritten = "<?php\n\nreturn ['prefix' => 'horizon:'];\n";
    file_put_contents($path, $rewritten);

    // Upstream is free to revise its own stub, and guessing at the replacement would be
    // worse than reporting the repair was not made.
    expect(new PublishedStubRepairer($targetPath)->repair())->toBe([])
        ->and((string) file_get_contents($path))->toBe($rewritten);
});

it('reports nothing when the stubs were never published', function (): void {
    expect(new PublishedStubRepairer(tempDir())->repair())->toBe([]);
});
