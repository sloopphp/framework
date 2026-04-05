<?php

declare(strict_types=1);

namespace Sloop\Error;

use Psr\Log\LogLevel;
use Sloop\Http\HttpStatus;

/**
 * Infrastructure-level exception for external system failures.
 *
 * Examples: database connection failure, external API outage, queue unavailable.
 * These are typically transient and retryable.
 * Default: HTTP 503 / error.
 */
class InfrastructureException extends SloopException
{
    /**
     * HTTP status code for infrastructure exceptions.
     *
     * @var int
     */
    public protected(set) int $statusCode = HttpStatus::ServiceUnavailable;

    /**
     * PSR-3 log level for infrastructure exceptions.
     *
     * @var string
     */
    public protected(set) string $logLevel = LogLevel::ERROR;
}
