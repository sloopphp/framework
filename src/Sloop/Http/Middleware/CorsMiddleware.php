<?php

declare(strict_types=1);

namespace Sloop\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Http\HttpStatus;
use Sloop\Support\Arr;

/**
 * CORS middleware for cross-origin resource sharing.
 *
 * Handles preflight OPTIONS requests and adds CORS headers
 * to responses based on configuration.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * Default string-list config values.
     *
     * Used as fallback when the corresponding config key is missing
     * or contains a non-array value.
     *
     * @var array<string, list<string>>
     */
    private const array STRING_LIST_DEFAULTS = [
        'allowed_origins' => [],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
        'allowed_headers' => ['Content-Type', 'Authorization'],
    ];

    /**
     * Default preflight cache max age in seconds.
     *
     * @var int
     */
    private const int DEFAULT_MAX_AGE = 86400;

    /**
     * Default credentials policy.
     *
     * @var bool
     */
    private const bool DEFAULT_ALLOW_CREDENTIALS = false;

    /**
     * Allowed origins.
     *
     * @var list<string>
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
     * Create a new CORS middleware.
     *
     * @param array<string, mixed> $config CORS configuration
     */
    public function __construct(array $config = [])
    {
        $this->allowedOrigins   = Arr::stringList($config, 'allowed_origins', self::STRING_LIST_DEFAULTS['allowed_origins']);
        $this->allowedMethods   = Arr::stringList($config, 'allowed_methods', self::STRING_LIST_DEFAULTS['allowed_methods']);
        $this->allowedHeaders   = Arr::stringList($config, 'allowed_headers', self::STRING_LIST_DEFAULTS['allowed_headers']);
        $this->maxAge           = \is_int($config['max_age'] ?? null) ? $config['max_age'] : self::DEFAULT_MAX_AGE;
        $this->allowCredentials = (bool) ($config['allow_credentials'] ?? self::DEFAULT_ALLOW_CREDENTIALS);
    }

    /**
     * Process the request and apply CORS headers.
     *
     * Preflight OPTIONS requests are handled immediately with a 204 response.
     * Other requests are forwarded to the next handler with CORS headers applied.
     *
     * @param ServerRequestInterface  $request Incoming request
     * @param RequestHandlerInterface $handler Next handler
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
     * Check if the given origin is allowed.
     *
     * @param string $origin Request origin
     * @return bool
     */
    private function isOriginAllowed(string $origin): bool
    {
        if (\in_array('*', $this->allowedOrigins, true)) {
            return true;
        }

        return \in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * Handle a preflight OPTIONS request.
     *
     * @param string $origin Allowed origin
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
     * @param ResponseInterface $response Response to modify
     * @param string            $origin   Allowed origin
     * @return ResponseInterface
     */
    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withAddedHeader('Vary', 'Origin');

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
