<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * reflectParameters のキャッシュキー検証用スタブ。
 *
 * CacheKeyA::bc と CacheKeyAb::c は、クラス名とメソッド名を区切りなしで
 * 連結すると同じ文字列（...\CacheKeyAbc）になる。区切りが失われると
 * 両者が同じキャッシュエントリを共有する。
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
