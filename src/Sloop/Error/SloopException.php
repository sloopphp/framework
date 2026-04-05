<?php

declare(strict_types=1);

namespace Sloop\Error;

use Psr\Log\LogLevel;
use RuntimeException;
use Sloop\Http\HttpStatus;
use Throwable;

/**
 * Base exception for all Sloop application exceptions.
 *
 * Carries HTTP status code and PSR-3 log level metadata so that
 * exception handlers can respond and log consistently.
 */
abstract class SloopException extends RuntimeException
{
    /**
     * HTTP status code for this exception type.
     *
     * @var int
     */
    public protected(set) int $statusCode = HttpStatus::InternalServerError;

    /**
     * PSR-3 log level for this exception type.
     *
     * @var string
     */
    public protected(set) string $logLevel = LogLevel::ERROR;

    /**
     * Create a new Sloop exception.
     *
     * @param string         $message    Error message
     * @param int            $statusCode HTTP status code (0 or negative = use class default)
     * @param string         $logLevel   PSR-3 log level ('' = use class default)
     * @param Throwable|null $previous   Previous exception for chaining
     * @return void
     */
    public function __construct(
        string $message = '',
        int $statusCode = 0,
        string $logLevel = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        if ($statusCode > 0) {
            $this->statusCode = $statusCode;
        }

        if ($logLevel !== '') {
            $this->logLevel = $logLevel;
        }
    }
}
