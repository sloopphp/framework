<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * reflectParameters のキャッシュキー検証用スタブ。
 *
 * CacheKeySameMethodB と同じメソッド名 show を持ち、引数の型だけが異なる。
 * キャッシュキーからクラス名が欠落すると両者が同じエントリを共有し、
 * どちらが先にキャッシュされても他方が TypeError になる。
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
