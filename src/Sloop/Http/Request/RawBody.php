<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use Closure;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading of the raw request body with an upper limit on its size.
 *
 * Only what is read from php://input is counted, so a form PHP has already
 * parsed (and its uploaded files) reads as empty and is left to PHP's own
 * limits. A body longer than the limit is not kept: at most one byte past the
 * limit is read, and the request is marked instead of refused, because it is
 * built before the error boundary exists; Application answers a marked
 * request with a 413 from inside it.
 *
 * @internal Used by Application when it builds the request from the globals.
 */
final class RawBody
{
    /**
     * Request attribute holding the mark of a body longer than the limit.
     *
     * @var string
     */
    private const string TOO_LARGE = self::class . '::tooLarge';

    /**
     * Attach the raw body to the request, or mark the request when the body is longer than the limit.
     *
     * An empty or unreadable body leaves the request as it is.
     *
     * @param  ServerRequestInterface               $request Request built from the globals
     * @param  Closure(int<1, max>): (string|false) $read    Reads at most the given number of bytes of the body
     * @param  int<0, max>                          $limit   Largest body size accepted, in bytes
     * @return ServerRequestInterface
     */
    public static function read(ServerRequestInterface $request, Closure $read, int $limit): ServerRequestInterface
    {
        $body = $read($limit + 1);
        if ($body === false || $body === '') {
            return $request;
        }

        if (\strlen($body) > $limit) {
            return $request->withAttribute(self::TOO_LARGE, true);
        }

        return $request->withBody(Stream::create($body));
    }

    /**
     * Whether read() found a body longer than the limit.
     *
     * @param  ServerRequestInterface $request Request returned by read()
     * @return bool
     */
    public static function isTooLarge(ServerRequestInterface $request): bool
    {
        return $request->getAttribute(self::TOO_LARGE) === true;
    }
}
