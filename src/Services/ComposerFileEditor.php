<?php

declare(strict_types=1);

namespace Kalimera\Services;

use Kalimera\Exceptions\ComposerFileUnreadableException;

class ComposerFileEditor
{
    /** @var array<string, mixed> */
    private array $contents;

    public function __construct(private readonly string $path)
    {
        $raw = file_get_contents($this->path);

        if ($raw === false) {
            throw ComposerFileUnreadableException::make($this->path);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(associative: true, flags: JSON_THROW_ON_ERROR, json: $raw);

        $this->contents = $decoded;
    }

    public function addPsr4(string $namespace, string $path): void
    {
        $this->contents['autoload']['psr-4'][$namespace] = $path;
    }

    /**
     * @param  list<string>|string  $script
     */
    public function addScript(string $name, array|string $script): void
    {
        $this->contents['scripts'][$name] = $script;
    }

    /**
     * @param  list<string>  $entries
     */
    public function appendScript(string $name, array $entries): void
    {
        $current = (array) ($this->contents['scripts'][$name] ?? []);

        foreach ($entries as $entry) {
            if (! in_array($entry, $current, true)) {
                $current[] = $entry;
            }
        }

        $this->contents['scripts'][$name] = $current;
    }

    public function save(): void
    {
        $encoded = json_encode(
            $this->contents,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        (new FileWriter)(contents: $encoded."\n", path: $this->path);
    }

    public function setPhpConstraint(string $constraint): void
    {
        $require = ['php' => $constraint];

        foreach ((array) ($this->contents['require'] ?? []) as $package => $version) {
            if ($package !== 'php') {
                $require[$package] = $version;
            }
        }

        $this->contents['require'] = $require;
    }
}
