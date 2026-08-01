<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * CacheKeySameMethodA の対。詳細は同クラスの説明を参照。
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
