<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
    ])
    ->withSkip([
        __DIR__.'/var',
        __DIR__.'/vendor',
        __DIR__.'/migrations',
        __DIR__.'/tests',
        __DIR__.'/src/Kernel.php',
    ])
    ->withSets([
        SetList::PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::CODING_STYLE,
    ])
    ->withPhpVersion(PhpVersion::PHP_83)
;
