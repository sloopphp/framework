<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Sloop\Support\Arr;

/**
 * HTTP request wrapper with convenient API helpers.
 *
 * Wraps a PSR-7 ServerRequestInterface to provide a fluent,
 * developer-friendly API for common request operations.
 */
final class Request
{
    /**
     * Cached parsed JSON body.
     *
     * @var array<array-key, mixed>|null
     */
    private ?array $jsonCache = null;

    /**
     * Create a new request instance.
     *
     * @param ServerRequestInterface  $serverRequest PSR-7 server request
     * @param array<string, string>   $routeParams   Route parameters resolved by the router
     */
    public function __construct(
        private readonly ServerRequestInterface $serverRequest,
        private readonly array $routeParams = [],
    ) {
    }

    /**
     * Get a query string parameter.
     *
     * @param string $key     Parameter name
     * @param mixed  $default Default value if not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->serverRequest->getQueryParams()[$key] ?? $default;
    }

    /**
     * Get a parsed body parameter (form POST data).
     *
     * @param string $key     Parameter name
     * @param mixed  $default Default value if not found
     * @return mixed
     */
    public function post(string $key, mixed $default = null): mixed
    {
        $body = $this->serverRequest->getParsedBody();
        if (\is_array($body)) {
            return $body[$key] ?? $default;
        }

        return $default;
    }

    /**
     * Get an input value from query string or parsed body.
     *
     * Query string is checked first, then parsed body.
     *
     * @param string $key     Parameter name
     * @param mixed  $default Default value if not found
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $query = $this->serverRequest->getQueryParams()[$key] ?? null;
        if ($query !== null) {
            return $query;
        }

        return $this->post($key, $default);
    }

    /**
     * Get a value from the parsed JSON body using dot notation.
     *
     * @param string|null $key     Dot-notation key (null returns entire body)
     * @param mixed       $default Default value if not found
     * @return mixed
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->jsonCache === null) {
            $body            = $this->serverRequest->getParsedBody();
            $this->jsonCache = \is_array($body) ? $body : [];
        }

        if ($key === null) {
            return $this->jsonCache;
        }

        return Arr::get($this->jsonCache, $key, $default);
    }

    /**
     * Get the client IP address.
     *
     * @return string|null
     */
    public function ip(): ?string
    {
        $addr = $this->serverRequest->getServerParams()['REMOTE_ADDR'] ?? null;

        return \is_string($addr) ? $addr : null;
    }

    /**
     * Get a request header value.
     *
     * @param string      $name    Header name (case-insensitive)
     * @param string|null $default Default value if not found
     * @return string|null
     */
    public function header(string $name, ?string $default = null): ?string
    {
        if (!$this->serverRequest->hasHeader($name)) {
            return $default;
        }

        return $this->serverRequest->getHeaderLine($name);
    }

    /**
     * Determine if the request is an AJAX request.
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Get the URL extension (e.g., 'json' from '/users.json').
     *
     * @return string|null Extension without the dot, or null if none
     */
    public function extension(): ?string
    {
        $path        = $this->serverRequest->getUri()->getPath();
        $lastSegment = basename($path);
        $dotPos      = strrpos($lastSegment, '.');

        if ($dotPos === false || $dotPos === 0) {
            return null;
        }

        return substr($lastSegment, $dotPos + 1);
    }

    /**
     * Get an uploaded file by name.
     *
     * @param string $name File input name
     * @return UploadedFileInterface|null
     */
    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->serverRequest->getUploadedFiles()[$name] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /**
     * Get the HTTP method (GET, POST, PUT, etc.).
     *
     * @return string
     */
    public function method(): string
    {
        return $this->serverRequest->getMethod();
    }

    /**
     * Parse the Authorization header into scheme and credentials.
     *
     * Supports any `<scheme> <credentials>` format such as Bearer,
     * Basic, Digest, or AWS4-HMAC-SHA256. The scheme is normalized
     * to lowercase for case-insensitive comparison.
     *
     * @return array{scheme: string, credentials: string}|null
     */
    public function authorization(): ?array
    {
        $authorization = $this->header('Authorization');
        if ($authorization === null) {
            return null;
        }

        $separator = strpos($authorization, ' ');
        if ($separator === false) {
            return null;
        }

        return [
            'scheme'      => strtolower(substr($authorization, 0, $separator)),
            'credentials' => substr($authorization, $separator + 1),
        ];
    }

    /**
     * Get the Bearer token from the Authorization header.
     *
     * @return string|null Token without the 'Bearer ' prefix, or null
     */
    public function bearerToken(): ?string
    {
        $auth = $this->authorization();

        return $auth !== null && $auth['scheme'] === 'bearer' ? $auth['credentials'] : null;
    }

    /**
     * Get a route parameter.
     *
     * @param string      $name    Parameter name
     * @param string|null $default Default value if not found
     * @return string|null
     */
    public function param(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    /**
     * Get the underlying PSR-7 server request.
     *
     * @return ServerRequestInterface
     */
    public function psrRequest(): ServerRequestInterface
    {
        return $this->serverRequest;
    }
}
