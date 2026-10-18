<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Sloop\Error\DomainException;
use Sloop\Http\HttpStatus;
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
     * @param ServerRequestInterface $serverRequest PSR-7 server request
     * @param array<string, string>  $routeParams   Route parameters resolved by the router
     */
    public function __construct(
        private readonly ServerRequestInterface $serverRequest,
        private readonly array $routeParams = [],
    ) {
    }

    /**
     * Get a query string parameter as a string.
     *
     * @param  string          $key     Parameter name
     * @param  string|null     $default Default value if not found
     * @return string|null
     * @throws DomainException When the value is not a string (HTTP 400)
     */
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->stringValue($key, $this->queryValue($key), $default);
    }

    /**
     * Get a parsed body parameter as a string.
     *
     * For a request Sloop builds from the globals, the parsed body holds the
     * JSON body when the request was sent with a JSON content type, and the
     * form values otherwise. A request built elsewhere holds whatever its
     * builder put there. A JSON number or boolean is not a string; read it
     * with json().
     *
     * @param  string          $key     Parameter name
     * @param  string|null     $default Default value if not found
     * @return string|null
     * @throws DomainException When the value is not a string (HTTP 400)
     */
    public function post(string $key, ?string $default = null): ?string
    {
        return $this->stringValue($key, $this->bodyValue($key), $default);
    }

    /**
     * Get an input value from query string or parsed body as a string.
     *
     * Query string is checked first, then parsed body. A value in the query
     * string is used even when it is not a string, so the body is not read.
     *
     * @param  string          $key     Parameter name
     * @param  string|null     $default Default value if not found
     * @return string|null
     * @throws DomainException When the value is not a string (HTTP 400)
     */
    public function input(string $key, ?string $default = null): ?string
    {
        $query = $this->queryValue($key);
        if ($query !== null) {
            return $this->stringValue($key, $query, $default);
        }

        return $this->post($key, $default);
    }

    /**
     * Get a query string parameter that holds an array, as it was sent.
     *
     * @param  string                  $key     Parameter name
     * @param  array<array-key, mixed> $default Default value if not found
     * @return array<array-key, mixed>
     * @throws DomainException         When the value is not an array (HTTP 400)
     */
    public function getArray(string $key, array $default = []): array
    {
        return $this->arrayValue($key, $this->queryValue($key), $default);
    }

    /**
     * Get a parsed body parameter that holds an array, as it was sent.
     *
     * The elements keep the types of the parsed body, such as the numbers and
     * booleans of a JSON body.
     *
     * @param  string                  $key     Parameter name
     * @param  array<array-key, mixed> $default Default value if not found
     * @return array<array-key, mixed>
     * @throws DomainException         When the value is not an array (HTTP 400)
     */
    public function postArray(string $key, array $default = []): array
    {
        return $this->arrayValue($key, $this->bodyValue($key), $default);
    }

    /**
     * Get an input value that holds an array from query string or parsed body.
     *
     * Query string is checked first, then parsed body, in the same way as
     * input().
     *
     * @param  string                  $key     Parameter name
     * @param  array<array-key, mixed> $default Default value if not found
     * @return array<array-key, mixed>
     * @throws DomainException         When the value is not an array (HTTP 400)
     */
    public function inputArray(string $key, array $default = []): array
    {
        $query = $this->queryValue($key);
        if ($query !== null) {
            return $this->arrayValue($key, $query, $default);
        }

        return $this->postArray($key, $default);
    }

    /**
     * Get a value from the parsed body using dot notation.
     *
     * Meant for a request sent with a JSON content type, whose parsed body is
     * the JSON body when Sloop builds the request from the globals; for a form
     * it reads the form values.
     *
     * @param  string|null $key     Dot-notation key (null returns entire body)
     * @param  mixed       $default Default value if not found
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
     * @param  string      $name    Header name (case-insensitive)
     * @param  string|null $default Default value if not found
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
     * @param  string                     $name File input name
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
     * @param  string      $name    Parameter name
     * @param  string|null $default Default value if not found
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

    /**
     * Get the raw query string value for a key.
     *
     * @param  string $key Parameter name
     * @return mixed  The value, or null when the key is missing
     */
    private function queryValue(string $key): mixed
    {
        return $this->serverRequest->getQueryParams()[$key] ?? null;
    }

    /**
     * Get the raw parsed body value for a key.
     *
     * @param  string $key Parameter name
     * @return mixed  The value, or null when the key is missing or the body is not an array
     */
    private function bodyValue(string $key): mixed
    {
        $body = $this->serverRequest->getParsedBody();

        return \is_array($body) ? $body[$key] ?? null : null;
    }

    /**
     * Narrow a raw request value to a string.
     *
     * @param  string          $key     Parameter name, used in the error message
     * @param  mixed           $value   Raw value, null when missing
     * @param  string|null     $default Value returned when the raw value is null
     * @return string|null
     * @throws DomainException When the value is neither null nor a string (HTTP 400)
     */
    private function stringValue(string $key, mixed $value, ?string $default): ?string
    {
        if ($value === null) {
            return $default;
        }

        if (!\is_string($value)) {
            throw new DomainException('The request value "' . $key . '" must be a string.', HttpStatus::BadRequest);
        }

        return $value;
    }

    /**
     * Narrow a raw request value to an array.
     *
     * @param  string                  $key     Parameter name, used in the error message
     * @param  mixed                   $value   Raw value, null when missing
     * @param  array<array-key, mixed> $default Value returned when the raw value is null
     * @return array<array-key, mixed>
     * @throws DomainException         When the value is neither null nor an array (HTTP 400)
     */
    private function arrayValue(string $key, mixed $value, array $default): array
    {
        if ($value === null) {
            return $default;
        }

        if (!\is_array($value)) {
            throw new DomainException('The request value "' . $key . '" must be an array.', HttpStatus::BadRequest);
        }

        return $value;
    }
}
