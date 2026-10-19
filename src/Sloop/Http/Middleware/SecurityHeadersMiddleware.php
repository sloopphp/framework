<?php

declare(strict_types=1);

namespace Sloop\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that adds security headers to every response.
 *
 * The application places it outside all other global middleware, so CORS
 * preflight responses and error responses rendered by the application also
 * receive the headers, including the error response for an exception thrown
 * by a global middleware. A header the handler already set is kept as it is.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * Headers sent when the configuration does not override them.
     *
     * The set recommended for API responses by the OWASP REST Security Cheat Sheet,
     * without Cache-Control, which would stop public responses from being cached.
     *
     * @var array<string, string>
     */
    private const array DEFAULT_HEADERS = [
        'X-Content-Type-Options'  => 'nosniff',
        'X-Frame-Options'         => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'",
        'Referrer-Policy'         => 'no-referrer',
    ];

    /**
     * Headers added to responses, keyed by header name.
     *
     * @var array<string, string>
     */
    private array $headers;

    /**
     * Create the middleware from the defaults and the given overrides.
     *
     * A string sets the header, replacing a default of the same name
     * regardless of case; null removes a default.
     *
     * @param  array<array-key, mixed>   $overrides Header names mapped to a value or null
     * @throws \InvalidArgumentException If a key is not a string, a name or value is not a valid header, a value is empty or neither a string nor null, or two names differ only in case
     */
    public function __construct(array $overrides = [])
    {
        $headers = self::DEFAULT_HEADERS;
        $seen    = [];

        foreach ($overrides as $name => $value) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException(
                    'Headers must be keyed by header name; got the integer key ' . $name . '.',
                );
            }

            $key = strtolower($name);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(
                    'The header "' . $name . '" is given twice with different case ("' . $seen[$key] . '").',
                );
            }

            $seen[$key] = $name;
            $headers    = array_filter(
                $headers,
                static fn (string $existing): bool => strtolower($existing) !== $key,
                ARRAY_FILTER_USE_KEY,
            );

            if ($value === null) {
                // A misspelled name would leave the default in place without notice.
                self::assertValidName($name);

                continue;
            }

            $headers[$name] = self::validHeaderValue($name, $value);
        }

        $this->headers = $headers;
    }

    /**
     * Add the configured headers that the response does not already have.
     *
     * @param  ServerRequestInterface  $request Incoming request
     * @param  RequestHandlerInterface $handler Next handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * Check that a name given with null can be sent as a header name.
     *
     * @param  string                    $name Header name
     * @return void
     * @throws \InvalidArgumentException If the name is not a valid header name
     */
    private static function assertValidName(string $name): void
    {
        // The value only completes the call; any valid value would do.
        self::validHeaderValue($name, 'checked');
    }

    /**
     * Return the value after checking that the name and value can be sent as a header.
     *
     * The PSR-7 implementation would otherwise reject them on every
     * response, so the check runs once here with the same rules.
     *
     * @param  string                    $name  Header name
     * @param  mixed                     $value Header value
     * @return string
     * @throws \InvalidArgumentException If the value is not a non-empty string, or the name or value is not a valid header
     */
    private static function validHeaderValue(string $name, mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException(
                'The value of the header "' . $name . '" must be a non-empty string or null.',
            );
        }

        try {
            new Response()->withHeader($name, $value);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                'The header "' . $name . '" has an invalid name or value: ' . $e->getMessage(),
                previous: $e,
            );
        }

        return $value;
    }
}
