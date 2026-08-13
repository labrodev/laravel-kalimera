<?php

declare(strict_types=1);

namespace Kalimera\Payloads;

readonly class AdditionalPackage
{
    /**
     * @param  list<string>  $publishProviders
     */
    public function __construct(
        public string $package,
        public string $label,
        public bool $dev = false,
        public bool $preselected = false,
        public array $publishProviders = [],
    ) {}
}
