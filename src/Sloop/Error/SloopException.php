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
 *
 * @api
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
     * Valid PSR-3 log levels.
     *
     * @var list<string>
     */
    private const array VALID_LOG_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * Create a new Sloop exception.
     *
     * @param string         $message    Error message
     * @param int            $statusCode HTTP status code (0 or negative = use class default)
     * @param string         $logLevel   PSR-3 log level ('' = use class default)
     * @param Throwable|null $previous   Previous exception for chaining
     * @return void
     * @throws \InvalidArgumentException When $logLevel is not a valid PSR-3 level
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
            // An invalid level string would otherwise surface much later as an
            // InvalidArgumentException inside the logger — i.e. while the
            // exception handler is processing this very exception — turning a
            // handled error into an unhandled one. Fail at the throw site instead.
            if (!\in_array($logLevel, self::VALID_LOG_LEVELS, true)) {
                throw new \InvalidArgumentException(
                    'Invalid PSR-3 log level: ' . $logLevel
                );
            }

            $this->logLevel = $logLevel;
        }
    }
}
