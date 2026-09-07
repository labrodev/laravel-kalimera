<?php

declare(strict_types=1);

namespace Kalimera\Services;

/**
 * Picks a `.bak` name beside a file that nothing else holds.
 *
 * A run that fails twice over the same hand-edited file must not have its second backup
 * overwrite the first, so the suffix walks until it finds a free name. Shared because both
 * things that move a user's file aside — publishing a template over it, and clearing the
 * skeleton phpstan.neon — owe the same guarantee.
 */
readonly class BackupPath
{
    public function __invoke(string $target): string
    {
        $candidate = $target.'.bak';

        for ($suffix = 2; file_exists($candidate); $suffix++) {
            $candidate = $target.'.bak'.$suffix;
        }

        return $candidate;
    }
}
