<?php

declare(strict_types=1);

namespace Sloop\Http\Response;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Sloop\Http\HttpStatus;

/**
 * Default API response formatter.
 *
 * Produces a standardized JSON structure for API responses.
 * Success: {"data": ..., "meta": {...}}
 * Error:   {"error": {"message": "...", "status": 404, "errors": {...}}}
 *
 * Replace via Container binding to customize the response structure.
 */
final class ApiResponseFormatter implements ResponseFormatterInterface
{
    /**
     * Default JSON encoding options.
     *
     * @var int
     */
    public const int DEFAULT_JSON_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * Create a new API response formatter.
     *
     * @param int $jsonOptions JSON encoding options (bitwise flags)
     */
    public function __construct(
        private readonly int $jsonOptions = self::DEFAULT_JSON_OPTIONS,
    ) {
    }

    /**
     * Get the JSON encoding options.
     *
     * @return int Bitwise JSON encoding flags
     */
    public function getJsonOptions(): int
    {
        return $this->jsonOptions;
    }

    /**
     * Format a success response.
     *
     * @param  mixed                $data   Response data
     * @param  array<string, mixed> $meta   Metadata (pagination, etc.)
     * @param  int                  $status HTTP status code
     * @return ResponseInterface
     * @throws \JsonException If the data cannot be encoded to JSON
     */
    public function success(mixed $data, array $meta = [], int $status = HttpStatus::Ok): ResponseInterface
    {
        $body = ['data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return $this->jsonResponse($body, $status);
    }

    /**
     * Format an error response.
     *
     * @param  string               $message Error message
     * @param  int                  $status  HTTP status code
     * @param  array<string, mixed> $errors  Detailed errors (validation, etc.)
     * @return ResponseInterface
     * @throws \JsonException If the data cannot be encoded to JSON
     */
    public function error(string $message, int $status = HttpStatus::BadRequest, array $errors = []): ResponseInterface
    {
        $error = [
            'message' => $message,
            'status'  => $status,
        ];

        if ($errors !== []) {
            $error['errors'] = $errors;
        }

        return $this->jsonResponse(['error' => $error], $status);
    }

    /**
     * Create a JSON response with the given body and status.
     *
     * @param array<string, mixed> $body   Response body
     * @param int                  $status HTTP status code
     * @return ResponseInterface
     * @throws \JsonException If the body cannot be encoded to JSON
     */
    private function jsonResponse(array $body, int $status): ResponseInterface
    {
        $json = json_encode($body, $this->jsonOptions | JSON_THROW_ON_ERROR);

        return (new Psr7Response($status))
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(Stream::create($json));
    }
}
