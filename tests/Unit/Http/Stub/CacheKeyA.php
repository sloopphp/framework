<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * Stub for verifying the cache key of reflectParameters.
 *
 * CacheKeyA::bc and CacheKeyAb::c produce the same string (...\CacheKeyAbc)
 * when the class name and method name are concatenated without a separator.
 * If the separator is lost, both share the same cache entry.
 */
final class CacheKeyA
{
    public static mixed $lastArg = null;

    /** @noinspection PhpUnused */
    public function bc(Request $request, int $id): string
    {
        self::$lastArg = $id;
        return 'collisionA';
    }
}
