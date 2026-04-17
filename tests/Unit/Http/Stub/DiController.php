<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub;

use Sloop\Http\Request\Request;

/**
 * Stub controller exercising every method-level DI path supported by RouteRequestHandler.
 *
 * Each action records what it was invoked with into static properties so
 * tests can assert the resolved argument values. Methods are invoked
 * dynamically through RouteRequestHandler, hence the per-method
 * `@noinspection PhpUnused` annotations.
 */
final class DiController
{
    /**
     * Sloop Request captured by the most recent action call.
     *
     * @var Request|null
     */
    public static ?Request $lastRequest = null;

    /**
     * Route-parameter "id" value captured by the most recent action call.
     *
     * @var mixed
     */
    public static mixed $lastId = null;

    /**
     * Route-parameter "price" value captured by the most recent action call.
     *
     * @var mixed
     */
    public static mixed $lastPrice = null;

    /**
     * Route-parameter "flag" value captured by the most recent action call.
     *
     * @var mixed
     */
    public static mixed $lastFlag = null;

    /**
     * Route-parameter "name" value captured by the most recent action call.
     *
     * @var mixed
     */
    public static mixed $lastName = null;

    /**
     * Route-parameter "page" value captured by the most recent action call.
     *
     * @var mixed
     */
    public static mixed $lastPage = null;

    /**
     * Container-resolved DiService captured by the most recent action call.
     *
     * @var DiService|null
     */
    public static ?DiService $lastService = null;

    /**
     * Reset all captured values so each test starts from a clean slate.
     *
     * @return void
     */
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
     * Legacy signature without type hints — exercises the positional fallback path.
     *
     * @param  mixed $request Legacy positional first argument (typically Request)
     * @param  mixed $id      Legacy positional route parameter
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpMissingParamTypeInspection, PhpUnused
     */
    public function legacyUntyped($request, $id): string
    {
        self::$lastRequest = $request instanceof Request ? $request : null;
        self::$lastId      = $id;
        return 'legacy';
    }

    /**
     * Single Request-typed parameter — verifies Sloop Request injection.
     *
     * @param  Request $request Injected Sloop request
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpUnused
     */
    public function requestOnly(Request $request): string
    {
        self::$lastRequest = $request;
        return 'requestOnly';
    }

    /**
     * Request plus int route parameter — verifies name-based int cast.
     *
     * @param  Request $request Injected Sloop request
     * @param  int     $id      Cast route parameter "id"
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpUnused
     */
    public function requestAndInt(Request $request, int $id): string
    {
        self::$lastRequest = $request;
        self::$lastId      = $id;
        return 'requestAndInt';
    }

    /**
     * Request plus float route parameter — verifies name-based float cast.
     *
     * @param  Request $request Injected Sloop request
     * @param  float   $price   Cast route parameter "price"
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpUnused
     */
    public function requestAndFloat(Request $request, float $price): string
    {
        self::$lastRequest = $request;
        self::$lastPrice   = $price;
        return 'requestAndFloat';
    }

    /**
     * Request plus bool route parameter — verifies name-based bool cast.
     *
     * @param  Request $request Injected Sloop request
     * @param  bool    $flag    Cast route parameter "flag"
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpUnused
     */
    public function requestAndBool(Request $request, bool $flag): string
    {
        self::$lastRequest = $request;
        self::$lastFlag    = $flag;
        return 'requestAndBool';
    }

    /**
     * Request plus string route parameter — verifies pass-through without casting.
     *
     * @param  Request $request Injected Sloop request
     * @param  string  $name    Route parameter "name" (string pass-through)
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpUnused
     */
    public function requestAndString(Request $request, string $name): string
    {
        self::$lastRequest = $request;
        self::$lastName    = $name;
        return 'requestAndString';
    }

    /**
     * Request plus object-typed dependency — verifies container-based injection.
     *
     * @param  Request   $request Injected Sloop request
     * @param  DiService $service Service resolved from the container
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpUnused
     */
    public function requestAndContainer(Request $request, DiService $service): string
    {
        self::$lastRequest = $request;
        self::$lastService = $service;
        return 'requestAndContainer';
    }

    /**
     * Builtin parameter with a default value — verifies fallback when route param is missing.
     *
     * @param  Request $request Injected Sloop request
     * @param  int     $page    Route parameter "page" or the declared default
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpUnused
     */
    public function builtinWithDefault(Request $request, int $page = 7): string
    {
        self::$lastRequest = $request;
        self::$lastPage    = $page;
        return 'builtinWithDefault';
    }

    /**
     * Union-typed parameter — verifies that unsupported union types raise an exception.
     *
     * @param  Request    $request Injected Sloop request
     * @param  int|string $id      Union-typed route parameter (unsupported)
     * @return string Marker identifying the invoked action
     *
     * @noinspection PhpUnused
     */
    public function unionTyped(Request $request, int|string $id): string
    {
        self::$lastRequest = $request;
        self::$lastId      = $id;
        return 'unionTyped';
    }
}
