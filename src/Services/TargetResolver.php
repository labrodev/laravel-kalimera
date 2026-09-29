<?php

declare(strict_types=1);

namespace Kalimera\Services;

readonly class TargetResolver
{
    public function __construct(
        private ?string $cwd = null,
        private ?string $home = null,
    ) {}

    public function validate(string $value): ?string
    {
        $path = $this->expandPath($value);

        if ($path === '') {
            return 'Enter an application name or a path.';
        }

        if (preg_match(pattern: '/^[a-z0-9][a-z0-9._-]*$/i', subject: basename($path)) !== 1) {
            return 'The application name may only contain letters, numbers, dots, dashes and underscores.';
        }

        $parent = dirname($this->absolutePath($path));

        if (! is_dir($parent)) {
            return sprintf('Parent directory %s does not exist.', $parent);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function resolve(string $value): array
    {
        $path = $this->absolutePath($this->expandPath($value));

        return [basename($path), $path];
    }

    private function expandPath(string $value): string
    {
        $value = rtrim(trim($value), '/');

        if ($value === '~' || str_starts_with($value, '~/')) {
            $value = ($this->home ?? $_SERVER['HOME'] ?? '').substr($value, 1);
        }

        return $value;
    }

    /**
     * Canonical wherever the parent exists: `app`, `./app` and the same directory reached
     * through a symlink are one application, and everything keyed on the path — the run
     * lock above all — has to see them as one. The application itself usually does not
     * exist yet, so only its parent can be resolved.
     */
    private function absolutePath(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : ($this->cwd ?? (string) getcwd()).'/'.$path;
        $parent = realpath(dirname($absolute));

        return $parent === false ? $absolute : rtrim($parent, '/').'/'.basename($absolute);
    }
}
