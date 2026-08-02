<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * Stub for verifying the cache key of reflectParameters.
 *
 * Has the same method name show as CacheKeySameMethodB, differing only in the
 * parameter type. If the class name is missing from the cache key, both share
 * the same entry, and whichever is cached first makes the other raise a TypeError.
 */
final class CacheKeySameMethodA
{
    public static mixed $lastArg = null;

    /** @noinspection PhpUnused */
    public function show(Request $request, int $id): string
    {
        self::$lastArg = $id;
        return 'sameMethodA';
    }
}
