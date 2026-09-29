<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Parsing of a JSON request body into the PSR-7 parsed body.
 *
 * PHP fills $_POST only for form bodies, so a request sent with a JSON
 * content type reaches the application with the JSON still in the raw body.
 * This puts it where the form values would be, whatever the HTTP method.
 *
 * A body that is not a JSON object or array cannot go there. The request is
 * marked instead of refused, because it is built before the error boundary
 * exists; Application refuses a marked request with a 400 from inside it.
 *
 * @internal Used by Application when it builds the request from the globals.
 */
final class JsonBody
{
    /**
     * Request attribute holding the mark of a body that could not be parsed.
     *
     * @var string
     */
    private const string MALFORMED = self::class . '::malformed';

    /**
     * Media types written with a `+json` suffix (RFC 6839), after the parameters are cut off.
     *
     * The subtype before the suffix uses the characters RFC 6838 allows in a
     * name, so `application/+json` is not one.
     *
     * @var string
     */
    private const string SUFFIXED = '/\Aapplication\/[a-z0-9][a-z0-9!#$&^_.+-]*\+json\z/';

    /**
     * Nesting depth given to json_decode(), which is its own default.
     *
     * json_decode() needs a depth one more than the number of nested arrays or
     * objects (`[]` needs 2), so a body nested 512 levels deep is refused and
     * one of 511 levels is read.
     *
     * @var int
     */
    private const int DEPTH = 512;

    /**
     * Parse the body of a request sent with a JSON content type.
     *
     * The content types are `application/json` and `application/*+json`, with
     * any parameters and in any letter case. An empty body gives an empty
     * array. An integer beyond the int range is read as a string, so that no
     * digit is lost. A request with another content type is returned as it is.
     *
     * @param  ServerRequestInterface $request Request built from the globals
     * @return ServerRequestInterface
     */
    public static function parse(ServerRequestInterface $request): ServerRequestInterface
    {
        if (!self::isJson($request->getHeaderLine('Content-Type'))) {
            return $request;
        }

        $body = (string) $request->getBody();
        if ($body === '') {
            return $request->withParsedBody([]);
        }

        try {
            $decoded = json_decode($body, true, self::DEPTH, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (!\is_array($decoded)) {
            return $request->withParsedBody([])->withAttribute(self::MALFORMED, true);
        }

        return $request->withParsedBody($decoded);
    }

    /**
     * Whether parse() found a body it could not read as a JSON object or array.
     *
     * @param  ServerRequestInterface $request Request returned by parse()
     * @return bool
     */
    public static function isMalformed(ServerRequestInterface $request): bool
    {
        return $request->getAttribute(self::MALFORMED) === true;
    }

    /**
     * Whether a Content-Type header names JSON.
     *
     * @param  string $contentType Content-Type header, possibly with parameters
     * @return bool
     */
    private static function isJson(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'application/json' || preg_match(self::SUFFIXED, $mediaType) === 1;
    }
}
