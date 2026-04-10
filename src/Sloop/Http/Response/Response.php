<?php

declare(strict_types=1);

namespace Sloop\Http\Response;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Sloop\Http\HttpStatus;

/**
 * HTTP response builder with convenient API helpers.
 *
 * Provides a fluent interface for building responses.
 * Uses the configured ResponseFormatterInterface for structured API responses.
 */
final class Response
{
    /**
     * Response data to be formatted.
     *
     * @var mixed
     */
    private mixed $data;

    /**
     * Response formatter for structured output.
     *
     * @var ResponseFormatterInterface
     */
    private ResponseFormatterInterface $formatter;

    /**
     * HTTP status code.
     *
     * @var int
     */
    private int $status = HttpStatus::Ok;

    /**
     * Response headers.
     *
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * Metadata for the response (pagination, etc.).
     *
     * @var array<string, mixed>
     */
    private array $meta = [];

    /**
     * Create a new response instance.
     *
     * @param mixed                      $data      Response data
     * @param ResponseFormatterInterface $formatter Response formatter
     */
    public function __construct(mixed $data, ResponseFormatterInterface $formatter)
    {
        $this->data      = $data;
        $this->formatter = $formatter;
    }

    /**
     * Set the HTTP status code.
     *
     * @param  int $status HTTP status code
     * @return self
     */
    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Set a response header.
     *
     * @param string $name  Header name
     * @param string $value Header value
     * @return self
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Set multiple response headers.
     *
     * @param array<string, string> $headers Headers to set
     * @return self
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    /**
     * Set response metadata (pagination, etc.).
     *
     * @param array<string, mixed> $meta Metadata
     * @return self
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * Build a JSON response using the configured formatter.
     *
     * @return ResponseInterface
     */
    public function json(): ResponseInterface
    {
        $response = $this->formatter->success($this->data, $this->meta, $this->status);

        return $this->applyHeaders($response);
    }

    /**
     * Build an error response using the configured formatter.
     *
     * @param  string               $message Error message
     * @param  int                  $status  HTTP status code
     * @param  array<string, mixed> $errors  Detailed errors (validation, etc.)
     * @return ResponseInterface
     */
    public function error(string $message, int $status = HttpStatus::BadRequest, array $errors = []): ResponseInterface
    {
        $response = $this->formatter->error($message, $status, $errors);

        return $this->applyHeaders($response);
    }

    /**
     * Build a raw response bypassing the formatter structure.
     *
     * String data is used as-is. Non-string data is JSON-encoded
     * using the formatter's JSON options.
     *
     * @param string $contentType Content-Type header value
     * @return ResponseInterface
     * @throws \JsonException If non-string data cannot be encoded to JSON
     */
    public function raw(string $contentType = 'application/json; charset=utf-8'): ResponseInterface
    {
        $body = \is_string($this->data)
            ? $this->data
            : json_encode($this->data, $this->formatter->getJsonOptions() | JSON_THROW_ON_ERROR);

        $response = (new Psr7Response($this->status))
            ->withHeader('Content-Type', $contentType)
            ->withBody(Stream::create($body));

        return $this->applyHeaders($response);
    }

    /**
     * Build a 201 Created response.
     *
     * @return ResponseInterface
     */
    public function created(): ResponseInterface
    {
        $this->status = HttpStatus::Created;

        return $this->json();
    }

    /**
     * Build a 204 No Content response.
     *
     * @return ResponseInterface
     */
    public function noContent(): ResponseInterface
    {
        return $this->applyHeaders(new Psr7Response(HttpStatus::NoContent));
    }

    /**
     * Build a redirect response.
     *
     * @param  string $url    Redirect URL
     * @param  int    $status HTTP status code (default 302)
     * @return ResponseInterface
     */
    public function redirect(string $url, int $status = HttpStatus::Found): ResponseInterface
    {
        $response = (new Psr7Response($status))->withHeader('Location', $url);

        return $this->applyHeaders($response);
    }

    /**
     * Apply custom headers to the response.
     *
     * @param ResponseInterface $response PSR-7 response
     * @return ResponseInterface
     */
    private function applyHeaders(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
