<?php

declare(strict_types=1);

namespace Kalimera\Services;

readonly class PackageListParser
{
    /**
     * @return list<string>
     */
    public function __invoke(string $answer): array
    {
        $tokens = preg_split(pattern: '/[\s,]+/', subject: trim($answer), limit: -1, flags: PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }
}
