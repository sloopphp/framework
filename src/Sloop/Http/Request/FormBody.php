<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use Closure;
use Psr\Http\Message\ServerRequestInterface;
use RequestParseBodyException;

/**
 * Parsing of a form body sent with PUT, PATCH or DELETE.
 *
 * PHP fills $_POST and $_FILES only for POST, so a form sent with another
 * method reaches the application with the form still in the raw body. This
 * reads it with request_parse_body() and puts the values and the files where
 * those of a POST would be.
 *
 * A body PHP refuses to read cannot go there. The request is marked instead
 * of refused, because it is built before the error boundary exists;
 * Application refuses a marked request with a 400 from inside it.
 *
 * @internal Used by Application when it builds the request from the globals.
 */
final class FormBody
{
    /**
     * Request attribute holding the mark of a body that could not be parsed.
     *
     * @var string
     */
    private const string MALFORMED = self::class . '::malformed';

    /**
     * Methods whose form body is parsed.
     *
     * RFC 9110 gives content in a GET, HEAD or DELETE request no defined
     * semantics; DELETE is kept because the frameworks this one follows read it.
     *
     * @var list<string>
     */
    private const array METHODS = ['PUT', 'PATCH', 'DELETE'];

    /**
     * Media types of the forms request_parse_body() reads.
     *
     * @var list<string>
     */
    private const array FORM_TYPES = ['application/x-www-form-urlencoded', 'multipart/form-data'];

    /**
     * Parse the form body of a PUT, PATCH or DELETE request.
     *
     * The content types are `application/x-www-form-urlencoded` and
     * `multipart/form-data`, with any parameters and in any letter case. The
     * files of a multipart body go through the same conversion as $_FILES. A
     * request with another method or content type is returned as it is.
     *
     * The parser reads php://input, and request_parse_body() finds nothing
     * there once something else has read it, so this runs before the raw body
     * is read.
     *
     * An urlencoded body longer than the limit is not parsed in full: the
     * parser is given the limit as its post_max_size and warns, and the
     * warning is kept out of the output. With a Content-Length it returns no
     * values; without one (chunked under mod_php) it may return what it read
     * up to the limit. The raw body stays readable, so
     * RawBody then marks the request. A multipart body is parsed under PHP's
     * own post_max_size, because the limit does not apply to its files.
     *
     * @param  ServerRequestInterface                                              $request Request built from the globals, before its body is read
     * @param  Closure(array<string, string>): array<int, array<array-key, mixed>> $parse   Reads the body as request_parse_body() does, with the given options: the values, then the files
     * @param  int<1, max>                                                         $limit   Largest urlencoded body parsed, in bytes (0 would mean no limit to the parser)
     * @return ServerRequestInterface
     * @throws \InvalidArgumentException                                           When an uploaded file carries an error code PHP does not define
     */
    public static function parse(ServerRequestInterface $request, Closure $parse, int $limit): ServerRequestInterface
    {
        $mediaType = self::mediaType($request->getHeaderLine('Content-Type'));
        if (!\in_array($request->getMethod(), self::METHODS, true) || !\in_array($mediaType, self::FORM_TYPES, true)) {
            return $request;
        }

        $options = $mediaType === 'multipart/form-data' ? [] : ['post_max_size' => (string) $limit];

        // The parser warns, instead of throwing, when an urlencoded body is
        // over post_max_size; with display_errors on, the warning would come
        // before the response. Only those warnings are kept back, one for a
        // body with a Content-Length and one for a body without; another one
        // (input variables over max_input_vars) still reaches PHP's handler.
        set_error_handler(
            static fn (int $errno, string $message): bool => str_contains($message, 'POST Content-Length of')
                || str_contains($message, 'Actual POST length does not match Content-Length'),
            \E_WARNING,
        );
        try {
            $parsed = $parse($options);
        } catch (RequestParseBodyException) {
            return $request->withParsedBody([])->withAttribute(self::MALFORMED, true);
        } finally {
            restore_error_handler();
        }

        return $request
            ->withParsedBody($parsed[0] ?? [])
            ->withUploadedFiles(UploadedFiles::fromGlobals($parsed[1] ?? []));
    }

    /**
     * Whether parse() found a body PHP refused to read.
     *
     * @param  ServerRequestInterface $request Request returned by parse()
     * @return bool
     */
    public static function isMalformed(ServerRequestInterface $request): bool
    {
        return $request->getAttribute(self::MALFORMED) === true;
    }

    /**
     * The media type of a Content-Type header, lowercased and without parameters.
     *
     * @param  string $contentType Content-Type header, possibly with parameters
     * @return string
     */
    private static function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }
}
