<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * Counterpart of CacheKeySameMethodA. See that class for details.
 */
final class CacheKeySameMethodB
{
    public static mixed $lastArg = null;

    /** @noinspection PhpUnused */
    public function show(Request $request, DiService $service): string
    {
        self::$lastArg = $service;
        return 'sameMethodB';
    }
}
