<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

use Sloop\Http\HttpStatus;

/**
 * Thrown when a database connection cannot be established.
 *
 * Covers: authentication failure, host unreachable, max_connections exhausted.
 * Default: HTTP 503 (service unavailable).
 */
class DatabaseConnectionException extends DatabaseException
{
    /**
     * HTTP status code for connection exceptions.
     *
     * @var int
     */
    public protected(set) int $statusCode = HttpStatus::ServiceUnavailable;
}
