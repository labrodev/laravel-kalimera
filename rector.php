<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ]);

    $rectorConfig->skip([
        __DIR__.'/templates',
        __DIR__.'/vendor',
    ]);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_84,
    ]);

    $rectorConfig->importNames();
    $rectorConfig->importShortClasses(false);
};
