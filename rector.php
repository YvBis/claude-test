<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Attribute\SortAttributeNamedArgsRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
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
        // Constructor promotion rewrites every entity/VO constructor — a
        // standalone refactor, not a dependency-bump side effect.
        ClassPropertyAssignToConstructorPromotionRector::class,
        // Named-arg order in attributes is authorial (OA blocks read in
        // semantic order); alphabetical sorting is pure churn.
        SortAttributeNamedArgsRector::class,
        SortCallLikeNamedArgsRector::class,
        // Property defaults are safety nets for non-constructor hydration:
        // Doctrine populates entities via reflection, bypassing the
        // constructor, so removing `= null`/`= true` turns uninitialized
        // typed-property Errors into runtime failures on partial paths.
        RemoveDefaultValueFromAssignedPropertyRector::class,
    ])
    ->withPhpSets()
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::CODING_STYLE,
    ])
    ->withPhpVersion(PhpVersion::PHP_83)
;
