<?php

declare(strict_types=1);

namespace Sloop\Error;

use Psr\Log\LogLevel;
use Sloop\Http\HttpStatus;

/**
 * Business-logic exception for expected application-level errors.
 *
 * Examples: validation failure, business rule violation, resource not found.
 * Default: HTTP 422 / warning.
 */
class DomainException extends SloopException
{
    /**
     * HTTP status code for domain exceptions.
     *
     * @var int
     */
    public protected(set) int $statusCode = HttpStatus::UnprocessableEntity;

    /**
     * PSR-3 log level for domain exceptions.
     *
     * @var string
     */
    public protected(set) string $logLevel = LogLevel::WARNING;
}
