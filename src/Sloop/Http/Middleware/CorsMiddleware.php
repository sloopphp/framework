<?php

declare(strict_types=1);

namespace Sloop\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Http\HttpStatus;

/**
 * CORS middleware for cross-origin resource sharing.
 *
 * Handles preflight OPTIONS requests and adds CORS headers
 * to responses from the allowed origins.
 */
final readonly class CorsMiddleware implements MiddlewareInterface
{
    /**
     * RFC 9110 token, the form of a method and a header name.
     *
     * @var string
     */
    private const string TOKEN_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /**
     * Allowed origins as scheme://host[:port], keyed by their lowercased serialization.
     *
     * @var array<string, true>
     */
    private array $allowedOrigins;

    /**
     * Allowed HTTP methods.
     *
     * @var list<string>
     */
    private array $allowedMethods;

    /**
     * Allowed request headers.
     *
     * @var list<string>
     */
    private array $allowedHeaders;

    /**
     * Preflight cache max age in seconds.
     *
     * @var int
     */
    private int $maxAge;

    /**
     * Whether credentials are allowed.
     *
     * @var bool
     */
    private bool $allowCredentials;

    /**
     * Whether the wildcard origin `*` is configured.
     *
     * @var bool
     */
    private bool $wildcardOrigin;

    /**
     * Create a new CORS middleware.
     *
     * Each origin is `*` or is written as scheme://host or scheme://host:port
     * the way browsers send the Origin header, as for CsrfMiddleware's trusted
     * origins; the scheme and host match without regard to case. Methods are
     * sent as written, and browsers match them with regard to case.
     *
     * @param  list<string>              $allowedOrigins   Origins allowed to read responses; `*` allows every origin
     * @param  list<string>              $allowedMethods   Methods allowed in a preflight
     * @param  list<string>              $allowedHeaders   Request headers allowed in a preflight
     * @param  int                       $maxAge           Seconds a browser may cache a preflight result
     * @param  bool                      $allowCredentials Whether requests with credentials may read responses
     * @throws \InvalidArgumentException If a list holds a value that is not a string, an origin is not written as above, a method or header is not an HTTP token, maxAge is negative, or `*` is allowed as an origin, a method or a header together with credentials
     */
    public function __construct(
        array $allowedOrigins = [],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'],
        array $allowedHeaders = ['Content-Type', 'Authorization'],
        int $maxAge = 86400,
        bool $allowCredentials = false,
    ) {
        $wildcard = false;
        $origins  = [];
        foreach (self::strings('allowedOrigins', $allowedOrigins) as $origin) {
            if ($origin === '*') {
                $wildcard = true;
            } else {
                $origins[Origin::serialize(Origin::configured($origin, false))] = true;
            }
        }

        if ($maxAge < 0) {
            throw new \InvalidArgumentException('The max age must be 0 or more seconds; ' . $maxAge . ' was given.');
        }

        // The Fetch spec forbids `Access-Control-Allow-Origin: *` together with
        // credentials; reflecting the request origin instead would silently
        // grant any site credentialed cross-origin reads, so fail loudly.
        if ($wildcard && $allowCredentials) {
            throw new \InvalidArgumentException('The origin "*" must not be allowed together with credentials.');
        }

        $methods = self::tokens('method', self::strings('allowedMethods', $allowedMethods));
        $headers = self::tokens('header', self::strings('allowedHeaders', $allowedHeaders));

        // For a request with credentials the Fetch spec reads `*` in
        // Access-Control-Allow-Methods / -Headers as a name, not a wildcard,
        // so it would allow nothing; ask for the names instead.
        if ($allowCredentials) {
            foreach (['method' => $methods, 'header' => $headers] as $kind => $values) {
                if (\in_array('*', $values, true)) {
                    throw new \InvalidArgumentException('The ' . $kind . ' "*" must not be allowed together with credentials; list the ' . $kind . 's instead.');
                }
            }
        }

        $this->allowedOrigins   = $origins;
        $this->allowedMethods   = $methods;
        $this->allowedHeaders   = $headers;
        $this->maxAge           = $maxAge;
        $this->allowCredentials = $allowCredentials;
        $this->wildcardOrigin   = $wildcard;
    }

    /**
     * Process the request and apply CORS headers.
     *
     * Preflight OPTIONS requests are handled immediately with a 204 response.
     * Other requests are forwarded to the next handler with CORS headers applied.
     *
     * @param  ServerRequestInterface  $request Incoming request
     * @param  RequestHandlerInterface $handler Next handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '' || !$this->isOriginAllowed($origin)) {
            return $handler->handle($request);
        }

        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflight($origin);
        }

        $response = $handler->handle($request);

        return $this->addCorsHeaders($response, $origin);
    }

    /**
     * Check that every value of a list given to the constructor is a string.
     *
     * The parameter is typed loosely so that the check is not dropped for
     * callers whose values are not checked by static analysis.
     *
     * @param  string                    $name   Parameter name, for the message
     * @param  array<mixed>              $values Values given
     * @return list<string>
     * @throws \InvalidArgumentException If a value is not a string
     */
    private static function strings(string $name, array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new \InvalidArgumentException($name . ' must hold only strings; ' . get_debug_type($value) . ' was given.');
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * Check that every value is an HTTP token, which a header line can carry.
     *
     * @param  string                    $kind   What the values name (method or header), for the message
     * @param  list<string>              $values Values given
     * @return list<string>
     * @throws \InvalidArgumentException If a value is not an HTTP token
     */
    private static function tokens(string $kind, array $values): array
    {
        foreach ($values as $value) {
            if (preg_match(self::TOKEN_PATTERN, $value) !== 1) {
                throw new \InvalidArgumentException('The ' . $kind . ' "' . $value . '" must be an HTTP token (letters, digits and !#$%&\'*+-.^_`|~).');
            }
        }

        return $values;
    }

    /**
     * Check if the given origin is allowed.
     *
     * @param  string $origin Request origin
     * @return bool
     */
    private function isOriginAllowed(string $origin): bool
    {
        return $this->wildcardOrigin || isset($this->allowedOrigins[strtolower($origin)]);
    }

    /**
     * Handle a preflight OPTIONS request.
     *
     * @param  string            $origin Allowed origin
     * @return ResponseInterface
     */
    private function handlePreflight(string $origin): ResponseInterface
    {
        $response = $this->addCorsHeaders(new Response(HttpStatus::NoContent), $origin);

        return $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }

    /**
     * Add CORS headers to the response.
     *
     * @param  ResponseInterface $response Response to modify
     * @param  string            $origin   Allowed origin
     * @return ResponseInterface
     */
    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        // With a wildcard config the literal `*` is sent instead of reflecting
        // the request origin; the response then no longer varies by Origin.
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $this->wildcardOrigin ? '*' : $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));

        // Guarded append: when this middleware is registered both globally and
        // per-route, the second pass must not produce `Vary: Origin, Origin`.
        if (!$this->wildcardOrigin && !$this->varyContainsOrigin($response)) {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Check whether the response's Vary header already lists Origin.
     *
     * @param  ResponseInterface $response Response to inspect
     * @return bool
     */
    private function varyContainsOrigin(ResponseInterface $response): bool
    {
        $values = array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', implode(',', $response->getHeader('Vary'))),
        );

        return \in_array('origin', $values, true);
    }
}
