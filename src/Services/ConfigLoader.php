<?php

declare(strict_types=1);

namespace Kalimera\Services;

use JsonException;
use Kalimera\Exceptions\InvalidConfigException;
use Kalimera\Payloads\AdditionalPackage;
use Kalimera\Payloads\InstallerConfig;

readonly class ConfigLoader
{
    private const string FILENAME = 'kalimera.config.json';

    private const array TOP_LEVEL_KEYS = ['$schema', 'preselected', 'additionalPackages'];

    private const array PRESELECTED_KEYS = [
        'starterKit', 'installInertia', 'aroundPackages', 'sailServices', 'phpConstraint',
        'qualityTools', 'installPostmark', 'installBoost', 'coreNamespace', 'boostAgents', 'boostSkillRepos',
        'extraPackages', 'extraDevPackages',
    ];

    private const array PACKAGE_KEYS = ['package', 'label', 'dev', 'preselected', 'publishProviders'];

    /**
     * Null looks for kalimera.config.json in the working directory and falls back to
     * the packaged setup; an explicit path must exist.
     */
    public function __invoke(?string $configPath): InstallerConfig
    {
        if ($configPath === '') {
            throw InvalidConfigException::make('The --config flag requires a path, e.g. --config=kalimera.config.json.');
        }

        $packaged = $this->packaged();

        if ($configPath === null) {
            $configPath = (string) getcwd().'/'.self::FILENAME;

            if (! file_exists($configPath)) {
                return $packaged;
            }
        } elseif (! file_exists($configPath)) {
            throw InvalidConfigException::make(sprintf('The config file %s does not exist.', $configPath));
        }

        return $this->merge(decoded: $this->decode($configPath), fallback: $packaged, source: $configPath);
    }

    /**
     * The packaged JSON Schema is the live source of kalimera's built-in setup: each
     * property's enum documents what is there (the available options), while the
     * JSON-Schema `default` keyword under "preselected" and "additionalPackages"
     * carries what is chosen out of the box. Editing the schema changes the offering;
     * a kalimera.config.json overrides it per project.
     */
    private function packaged(): InstallerConfig
    {
        $path = dirname(__DIR__, 2).'/schema/kalimera.config.schema.json';

        if (! file_exists($path)) {
            return InstallerConfig::builtIn();
        }

        $decoded = $this->decode($path);

        $config = [];

        $properties = isset($decoded['properties']) && is_array($decoded['properties']) ? $decoded['properties'] : [];

        foreach (['preselected', 'additionalPackages'] as $key) {
            if (isset($properties[$key]) && is_array($properties[$key]) && isset($properties[$key]['default']) && is_array($properties[$key]['default'])) {
                $config[$key] = $properties[$key]['default'];
            }
        }

        return $this->merge(decoded: $config, fallback: InstallerConfig::builtIn(), source: $path);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $path): array
    {
        try {
            $decoded = json_decode(associative: true, flags: JSON_THROW_ON_ERROR, json: (string) file_get_contents($path));
        } catch (JsonException $jsonException) {
            throw InvalidConfigException::make(sprintf('%s is not valid JSON: %s', $path, $jsonException->getMessage()));
        }

        if (! is_array($decoded)) {
            throw InvalidConfigException::make(sprintf('%s must contain a JSON object.', $path));
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     */
    private function merge(array $decoded, InstallerConfig $fallback, string $source): InstallerConfig
    {
        $this->assertKnownKeys(allowed: self::TOP_LEVEL_KEYS, keys: array_keys($decoded), source: $source, where: 'the top level');

        $additionalPackages = array_key_exists('additionalPackages', $decoded)
            ? $this->catalog(entries: $decoded['additionalPackages'], source: $source)
            : $fallback->additionalPackages;

        $preselected = $decoded['preselected'] ?? [];

        if (! is_array($preselected)) {
            throw InvalidConfigException::make(sprintf('%s: "preselected" must be a JSON object.', $source));
        }

        $this->assertKnownKeys(allowed: self::PRESELECTED_KEYS, keys: array_keys($preselected), source: $source, where: '"preselected"');

        $phpConstraint = $this->string(fallback: $fallback->phpConstraint, key: 'phpConstraint', source: $source, values: $preselected);

        if (preg_match(pattern: '/\d+\.\d+/', subject: $phpConstraint) !== 1) {
            throw InvalidConfigException::make(sprintf('%s: "preselected.phpConstraint" must contain a version like 8.5.', $source));
        }

        $coreNamespace = $this->coreNamespace(fallback: $fallback->coreNamespace, source: $source, values: $preselected);

        return new InstallerConfig(
            additionalPackages: $additionalPackages,
            starterKit: $this->choice(allowed: array_keys(OptionCollector::STARTER_KITS), fallback: $fallback->starterKit, key: 'starterKit', source: $source, values: $preselected),
            installInertia: $this->bool(fallback: $fallback->installInertia, key: 'installInertia', source: $source, values: $preselected),
            aroundPackages: $this->choiceList(allowed: array_keys(OptionCollector::AROUND_PACKAGES), fallback: $fallback->aroundPackages, key: 'aroundPackages', source: $source, values: $preselected),
            sailServices: $this->choiceList(allowed: array_keys(OptionCollector::SAIL_SERVICES), fallback: $fallback->sailServices, key: 'sailServices', source: $source, values: $preselected),
            phpConstraint: $phpConstraint,
            qualityTools: $this->choiceList(allowed: array_keys(OptionCollector::QUALITY_TOOLS), fallback: $fallback->qualityTools, key: 'qualityTools', source: $source, values: $preselected),
            installPostmark: $this->bool(fallback: $fallback->installPostmark, key: 'installPostmark', source: $source, values: $preselected),
            installBoost: $this->bool(fallback: $fallback->installBoost, key: 'installBoost', source: $source, values: $preselected),
            boostAgents: $this->choiceList(allowed: array_keys(OptionCollector::BOOST_AGENTS), fallback: $fallback->boostAgents, key: 'boostAgents', source: $source, values: $preselected),
            boostSkillRepos: $this->stringList(fallback: $fallback->boostSkillRepos, key: 'boostSkillRepos', source: $source, values: $preselected),
            extraPackages: $this->stringList(fallback: $fallback->extraPackages, key: 'extraPackages', source: $source, values: $preselected),
            extraDevPackages: $this->stringList(fallback: $fallback->extraDevPackages, key: 'extraDevPackages', source: $source, values: $preselected),
            coreNamespace: $coreNamespace,
        );
    }

    /**
     * @return list<AdditionalPackage>
     */
    private function catalog(mixed $entries, string $source): array
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw InvalidConfigException::make(sprintf('%s: "additionalPackages" must be a JSON array of package objects.', $source));
        }

        $catalog = [];
        $seen = [];

        foreach ($entries as $index => $entry) {
            $where = sprintf('"additionalPackages[%d]"', $index);

            if (! is_array($entry)) {
                throw InvalidConfigException::make(sprintf('%s: %s must be a JSON object.', $source, $where));
            }

            $this->assertKnownKeys(allowed: self::PACKAGE_KEYS, keys: array_keys($entry), source: $source, where: $where);

            $package = $entry['package'] ?? null;

            if (! is_string($package) || trim($package) === '') {
                throw InvalidConfigException::make(sprintf('%s: %s needs a non-empty "package" like vendor/package.', $source, $where));
            }

            if (isset($seen[$package])) {
                throw InvalidConfigException::make(sprintf('%s: "additionalPackages" lists %s twice.', $source, $package));
            }

            $seen[$package] = true;

            $label = $entry['label'] ?? $package;

            if (! is_string($label) || trim($label) === '') {
                throw InvalidConfigException::make(sprintf('%s: %s "label" must be a non-empty string.', $source, $where));
            }

            $dev = $entry['dev'] ?? false;
            $preselected = $entry['preselected'] ?? false;

            if (! is_bool($dev) || ! is_bool($preselected)) {
                throw InvalidConfigException::make(sprintf('%s: %s "dev" and "preselected" must be booleans.', $source, $where));
            }

            $publishProviders = $entry['publishProviders'] ?? [];

            if (! is_array($publishProviders) || $publishProviders !== array_filter($publishProviders, is_string(...))) {
                throw InvalidConfigException::make(sprintf('%s: %s "publishProviders" must be an array of provider class names.', $source, $where));
            }

            $catalog[] = new AdditionalPackage(
                dev: $dev,
                preselected: $preselected,
                label: $label,
                package: $package,
                publishProviders: array_values($publishProviders),
            );
        }

        return $catalog;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<array-key>  $keys
     */
    private function assertKnownKeys(array $allowed, array $keys, string $source, string $where): void
    {
        foreach ($keys as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $renamed = (string) $key === 'defaults' && $where === 'the top level' ? ' "defaults" was renamed to "preselected".' : '';

                throw InvalidConfigException::make(sprintf(
                    '%s: unknown key "%s" at %s — allowed: %s.%s',
                    $source,
                    (string) $key,
                    $where,
                    implode(', ', array_filter($allowed, fn (string $allowedKey): bool => $allowedKey !== '$schema')),
                    $renamed,
                ));
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $allowed
     */
    private function choice(array $allowed, string $fallback, string $key, string $source, array $values): string
    {
        $value = $this->string(fallback: $fallback, key: $key, source: $source, values: $values);

        if (! in_array($value, $allowed, true)) {
            throw InvalidConfigException::make(sprintf(
                '%s: "preselected.%s" must be one of %s, got "%s".',
                $source,
                $key,
                implode(', ', $allowed),
                $value,
            ));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $allowed
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private function choiceList(array $allowed, array $fallback, string $key, string $source, array $values): array
    {
        $list = $this->stringList(fallback: $fallback, key: $key, source: $source, values: $values);

        foreach ($list as $value) {
            if (! in_array($value, $allowed, true)) {
                throw InvalidConfigException::make(sprintf(
                    '%s: "preselected.%s" only accepts %s, got "%s".',
                    $source,
                    $key,
                    implode(', ', $allowed),
                    $value,
                ));
            }
        }

        return $list;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function string(string $fallback, string $key, string $source, array $values): string
    {
        if (! array_key_exists($key, $values)) {
            return $fallback;
        }

        if (! is_string($values[$key])) {
            throw InvalidConfigException::make(sprintf('%s: "preselected.%s" must be a string.', $source, $key));
        }

        return $values[$key];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private function stringList(array $fallback, string $key, string $source, array $values): array
    {
        if (! array_key_exists($key, $values)) {
            return $fallback;
        }

        $list = $values[$key];

        if (! is_array($list) || ! array_is_list($list) || $list !== array_filter($list, is_string(...))) {
            throw InvalidConfigException::make(sprintf('%s: "preselected.%s" must be an array of strings.', $source, $key));
        }

        return $list;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function bool(bool $fallback, string $key, string $source, array $values): bool
    {
        if (! array_key_exists($key, $values)) {
            return $fallback;
        }

        if (! is_bool($values[$key])) {
            throw InvalidConfigException::make(sprintf('%s: "preselected.%s" must be a boolean.', $source, $key));
        }

        return $values[$key];
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function coreNamespace(?string $fallback, string $source, array $values): ?string
    {
        if (! array_key_exists('coreNamespace', $values)) {
            return $fallback;
        }

        $value = $values['coreNamespace'];

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match(pattern: OptionCollector::NAMESPACE_PATTERN, subject: trim($value, '\\')) !== 1) {
            throw InvalidConfigException::make(sprintf(
                '%s: "preselected.coreNamespace" must be null or StudlyCase segments separated by backslashes, e.g. Core or Acme\\Core.',
                $source,
            ));
        }

        return trim($value, '\\');
    }
}
