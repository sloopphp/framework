<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * Stub controller exercising every method-level DI path supported by RouteRequestHandler.
 */
final class DiController
{
    public static ?Request $lastRequest = null;

    public static mixed $lastId = null;

    public static mixed $lastPrice = null;

    public static mixed $lastFlag = null;

    public static mixed $lastName = null;

    public static mixed $lastPage = null;

    public static ?DiService $lastService = null;

    public static function reset(): void
    {
        self::$lastRequest = null;
        self::$lastId      = null;
        self::$lastPrice   = null;
        self::$lastFlag    = null;
        self::$lastName    = null;
        self::$lastPage    = null;
        self::$lastService = null;
    }

    /**
     * @param mixed $request
     * @param mixed $id
     *
     * @noinspection PhpMissingParamTypeInspection, PhpUnused
     */
    public function legacyUntyped($request, $id): string
    {
        self::$lastRequest = $request instanceof Request ? $request : null;
        self::$lastId      = $id;
        return 'legacy';
    }

    /** @noinspection PhpUnused */
    public function requestOnly(Request $request): string
    {
        self::$lastRequest = $request;
        return 'requestOnly';
    }

    /** @noinspection PhpUnused */
    public function requestAndInt(Request $request, int $id): string
    {
        self::$lastRequest = $request;
        self::$lastId      = $id;
        return 'requestAndInt';
    }

    /** @noinspection PhpUnused */
    public function requestAndFloat(Request $request, float $price): string
    {
        self::$lastRequest = $request;
        self::$lastPrice   = $price;
        return 'requestAndFloat';
    }

    /** @noinspection PhpUnused */
    public function requestAndBool(Request $request, bool $flag): string
    {
        self::$lastRequest = $request;
        self::$lastFlag    = $flag;
        return 'requestAndBool';
    }

    /** @noinspection PhpUnused */
    public function requestAndString(Request $request, string $name): string
    {
        self::$lastRequest = $request;
        self::$lastName    = $name;
        return 'requestAndString';
    }

    /** @noinspection PhpUnused */
    public function requestAndContainer(Request $request, DiService $service): string
    {
        self::$lastRequest = $request;
        self::$lastService = $service;
        return 'requestAndContainer';
    }

    /** @noinspection PhpUnused */
    public function builtinWithDefault(Request $request, int $page = 7): string
    {
        self::$lastRequest = $request;
        self::$lastPage    = $page;
        return 'builtinWithDefault';
    }

    /** @noinspection PhpUnused */
    public function unionTyped(Request $request, int|string $id): string
    {
        self::$lastRequest = $request;
        self::$lastId      = $id;
        return 'unionTyped';
    }

    /** @noinspection PhpUnused */
    public function nullableInt(Request $request, ?int $id): string
    {
        self::$lastRequest = $request;
        self::$lastId      = $id;
        return 'nullableInt';
    }
}
