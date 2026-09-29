<?php

declare(strict_types=1);

namespace Kalimera\Services;

/**
 * Fortify, the starter kit and horizon publish stubs into app/ and config/, and three of
 * them do not survive the skeleton's own phpstan level: a validation trait whose @return
 * omits the rule object Rule::unique() actually hands back, a horizon gate that compares
 * against an empty array literal phpstan can prove nothing will ever match, and a config
 * value that passes env()'s bool|string straight into Str::slug().
 *
 * The alternative to repairing them is a baseline, which starts every scaffolded project
 * with its quality gate already conceding five findings — and a baseline entry outlives
 * the stub it was written for, so it keeps conceding them long after an upstream fix.
 *
 * A repair whose text is no longer there is skipped rather than forced. An upstream stub
 * that has since been rewritten is not something to guess at, and the run says so instead
 * of reporting a repair it did not make.
 */
readonly class PublishedStubRepairer
{
    /**
     * Each entry is the file, the text to find, and what to put in its place.
     *
     * @var list<array{string, string, string}>
     */
    private const array REPAIRS = [
        [
            'app/Concerns/ProfileValidationRules.php',
            '     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array',
            '     * @return array<string, array<int, ValidationRule|array<mixed>|string|\Illuminate\Validation\Rules\Unique>>
     */
    protected function profileRules(?int $userId = null): array',
        ],
        [
            'app/Concerns/ProfileValidationRules.php',
            '     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array',
            '     * @return array<int, ValidationRule|array<mixed>|string|\Illuminate\Validation\Rules\Unique>
     */
    protected function emailRules(?int $userId = null): array',
        ],
        [
            'app/Providers/HorizonServiceProvider.php',
            "        Gate::define('viewHorizon', fn (\$user = null): bool => in_array(\$user?->email, [
            //
        ], true));
    }",
            "        Gate::define('viewHorizon', fn (\$user = null): bool => in_array(\$user?->email, \$this->allowedEmails(), true));
    }

    /**
     * Email addresses that may reach Horizon outside the local environment.
     *
     * Empty by design: Horizon stays unreachable in production until this is filled in,
     * which is the safe default rather than a working configuration.
     *
     * @return list<string>
     */
    protected function allowedEmails(): array
    {
        return [];
    }",
        ],
        [
            'config/horizon.php',
            "        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'",
            "        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'",
        ],
    ];

    public function __construct(private string $targetPath) {}

    /**
     * @return list<string> The files that were changed, without repeats.
     */
    public function repair(): array
    {
        $repaired = [];

        foreach (self::REPAIRS as [$file, $search, $replacement]) {
            if ($this->replace(file: $file, replacement: $replacement, search: $search) && ! in_array($file, $repaired, true)) {
                $repaired[] = $file;
            }
        }

        return $repaired;
    }

    private function replace(string $file, string $search, string $replacement): bool
    {
        $path = $this->targetPath.'/'.$file;

        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        // Already applied, or written against a stub this version does not recognise.
        if (str_contains($contents, $replacement) || ! str_contains($contents, $search)) {
            return false;
        }

        (new FileWriter)(contents: str_replace(search: $search, replace: $replacement, subject: $contents), path: $path);

        return true;
    }
}
