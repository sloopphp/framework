<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * CacheKeyA の対。詳細は同クラスの説明を参照。
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
