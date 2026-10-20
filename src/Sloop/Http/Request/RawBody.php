<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use Closure;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading of the raw request body with an upper limit on its size.
 *
 * What is counted is what is read from php://input. A multipart body PHP has
 * already parsed reads as empty there, so for it the values of the fields
 * (not the files) are counted instead; its files are left to PHP's own
 * limits. A body longer than the limit is not kept: at most one byte past
 * the limit is read, and the request is marked instead of refused, because
 * it is built before the error boundary exists; Application answers a marked
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
     * An empty or unreadable body leaves the request as it is, unless the
     * names and values of its parsed fields add up to more than the limit.
     *
     * @param  ServerRequestInterface               $request Request built from the globals
     * @param  Closure(int<1, max>): (string|false) $read    Reads at most the given number of bytes of the body
     * @param  int<0, max>                          $limit   Largest body size accepted, in bytes
     * @return ServerRequestInterface
     */
    public static function read(ServerRequestInterface $request, Closure $read, int $limit): ServerRequestInterface
    {
        // One byte past the limit shows that the body is longer; PHP_INT_MAX
        // cannot be passed, and no body reaches it.
        $body = $read($limit === \PHP_INT_MAX ? $limit : $limit + 1);
        if ($body === false || $body === '') {
            return self::fieldBytes($request->getParsedBody()) > $limit
                ? $request->withAttribute(self::TOO_LARGE, true)
                : $request;
        }

        if (\strlen($body) > $limit) {
            return $request->withAttribute(self::TOO_LARGE, true);
        }

        return $request->withBody(Stream::create($body));
    }

    /**
     * The bytes in the names and string values of parsed fields, counted through nested arrays.
     *
     * @param  mixed $fields Parsed body
     * @return int
     */
    private static function fieldBytes(mixed $fields): int
    {
        if (!\is_array($fields)) {
            return 0;
        }

        $bytes = 0;
        foreach ($fields as $name => $value) {
            $bytes += \strlen((string) $name) + (\is_string($value) ? \strlen($value) : self::fieldBytes($value));
        }

        return $bytes;
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
