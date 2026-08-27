<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php84\Rector\Foreach_\ForeachToArrayAllRector;
use Rector\Php84\Rector\Foreach_\ForeachToArrayAnyRector;
use Rector\Php85\Rector\ArrayDimFetch\ArrayFirstLastRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/.claude',
    ])
    // The target version is detected automatically from require."php" (^8.5)
    // in composer.json. codingStyle / naming from withPreparedSets() are left
    // disabled: they mix in a large number of rewrites unrelated to the PHP
    // version, making the diff unreviewable.
    ->withPhpSets()
    ->withConfiguredRule(ClassPropertyAssignToConstructorPromotionRector::class, [
        // The default (true) renames constructor parameters to match property
        // names. That is a breaking change to the public API because it breaks
        // named arguments. Real case: LogManager's __construct(array $channels)
        // was renamed to $channelConfigs, and 106 tests calling
        // new LogManager(channels: [...]) failed. With false, promotion itself
        // is skipped when the parameter name and property name differ.
        ClassPropertyAssignToConstructorPromotionRector::RENAME_PROPERTY => false,
    ])
    ->withSkip([
        // A git worktree under .claude is a whole second checkout, vendor
        // included, and a parallel session writes into it while this runs.
        __DIR__ . '/.claude/worktrees',

        // docs/coding-standards.md and .claude/CLAUDE.md state that
        // array_any() / array_all() are not adopted (foreach is faster),
        // so these two rules from the PHP 8.4 set are not applied.
        ForeachToArrayAnyRector::class,
        ForeachToArrayAllRector::class,

        // Converts array_values($x)[0] to array_first($x). array_first()
        // returns null for an empty array, which widens the return type and
        // makes the property access right after it a type error at PHPStan
        // level max (11 spots in ConnectionTest). The pre-conversion code is
        // already type-safe, so the replacement is not worth adding null handling.
        ArrayFirstLastRector::class,
    ]);
