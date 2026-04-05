<?php

declare(strict_types=1);

namespace Sloop\Error;

use Psr\Log\LogLevel;
use Sloop\Http\HttpStatus;

/**
 * Fatal exception for unrecoverable application errors.
 *
 * Indicates the application cannot continue safely.
 * Triggers critical-level alerts and immediate attention.
 * Default: HTTP 500 / critical.
 */
class FatalException extends SloopException
{
    /**
     * HTTP status code for fatal exceptions.
     *
     * @var int
     */
    public protected(set) int $statusCode = HttpStatus::InternalServerError;

    /**
     * PSR-3 log level for fatal exceptions.
     *
     * @var string
     */
    public protected(set) string $logLevel = LogLevel::CRITICAL;
}
