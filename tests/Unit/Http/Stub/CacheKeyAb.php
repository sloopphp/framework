<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * Counterpart of CacheKeyA. See that class for details.
 */
final class CacheKeyAb
{
    public static mixed $lastArg = null;

    /** @noinspection PhpUnused */
    public function c(Request $request, DiService $service): string
    {
        self::$lastArg = $service;
        return 'collisionAb';
    }
}
