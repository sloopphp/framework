<?php

declare(strict_types=1);

namespace Sloop\Http\Response;

use Psr\Http\Message\ResponseInterface;
use Sloop\Http\HttpStatus;

/**
 * Response formatter interface for customizing API response structure.
 *
 * Implement this interface to define custom response formats
 * for success and error responses across the application.
 *
 * Scope: this interface covers only the JSON success/error envelope
 * produced by `Response::success()` / `Response::error()`. Other response
 * shapes on `Response` — `raw()`, `redirect()`, `noContent()`, `created()`
 * — intentionally bypass the formatter, because they do not carry the
 * application-level success/error semantics that an envelope would wrap.
 * Swapping the formatter therefore changes JSON API structure only; it
 * does not affect how plain bodies, redirects, or empty responses are
 * built.
 */
interface ResponseFormatterInterface
{
    /**
     * Get the JSON encoding options.
     *
     * @return int Bitwise JSON encoding flags
     */
    public function getJsonOptions(): int;

    /**
     * Format a success response.
     *
     * @param  mixed                $data   Response data
     * @param  array<string, mixed> $meta   Metadata (pagination, etc.)
     * @param  int                  $status HTTP status code
     * @return ResponseInterface
     */
    public function success(mixed $data, array $meta = [], int $status = HttpStatus::Ok): ResponseInterface;

    /**
     * Format an error response.
     *
     * @param  string               $message Error message
     * @param  int                  $status  HTTP status code
     * @param  array<string, mixed> $errors  Detailed errors (validation, etc.)
     * @return ResponseInterface
     */
    public function error(string $message, int $status = HttpStatus::BadRequest, array $errors = []): ResponseInterface;
}
