<?php

declare(strict_types=1);

namespace Sloop\Error;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Sloop\Http\HttpStatus;
use Throwable;

/**
 * Central exception handler that logs and resolves HTTP status codes.
 *
 * For SloopException instances, uses embedded metadata (log level, status code).
 * For all other Throwable instances, defaults to HTTP 500 / critical.
 */
final class ExceptionHandler
{
    /**
     * PSR-3 logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Create a new exception handler.
     *
     * @param LoggerInterface $logger PSR-3 logger for recording exceptions
     * @return void
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Handle an exception by logging it and returning the HTTP status code.
     *
     * @param Throwable $exception The exception to handle
     * @return int HTTP status code
     */
    public function handle(Throwable $exception): int
    {
        $statusCode = $this->resolveStatusCode($exception);
        $logLevel   = $this->resolveLogLevel($exception);

        $this->logger->log($logLevel, $exception->getMessage(), [
            'exception'   => $exception,
            'status_code' => $statusCode,
        ]);

        return $statusCode;
    }

    /**
     * Resolve the HTTP status code from the given exception.
     *
     * @param Throwable $exception The exception to inspect
     * @return int HTTP status code
     */
    public function resolveStatusCode(Throwable $exception): int
    {
        if ($exception instanceof SloopException) {
            return $exception->statusCode;
        }

        return HttpStatus::InternalServerError;
    }

    /**
     * Resolve the PSR-3 log level from the given exception.
     *
     * @param Throwable $exception The exception to inspect
     * @return string PSR-3 log level
     */
    public function resolveLogLevel(Throwable $exception): string
    {
        if ($exception instanceof SloopException) {
            return $exception->logLevel;
        }

        return LogLevel::CRITICAL;
    }
}
