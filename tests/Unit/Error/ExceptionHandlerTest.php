<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Error;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Sloop\Error\DomainException;
use Sloop\Error\ExceptionHandler;
use Sloop\Error\FatalException;
use Sloop\Error\InfrastructureException;
use Sloop\Http\HttpStatus;
use Stringable;

final class ExceptionHandlerTest extends TestCase
{
    private SpyLogger $logger;
    private ExceptionHandler $handler;

    protected function setUp(): void
    {
        $this->logger  = new SpyLogger();
        $this->handler = new ExceptionHandler($this->logger);
    }

    // ---------------------------------------------------------------
    // handle — SloopException instances
    // ---------------------------------------------------------------

    public function testHandleDomainExceptionReturns422(): void
    {
        $statusCode = $this->handler->handle(new DomainException('Bad input'));

        $this->assertSame(HttpStatus::UnprocessableEntity, $statusCode);
    }

    public function testHandleDomainExceptionLogsWarning(): void
    {
        $this->handler->handle(new DomainException('Bad input'));

        $this->assertSame(LogLevel::WARNING, $this->logger->lastLevel);
        $this->assertSame('Bad input', $this->logger->lastMessage);
    }

    public function testHandleInfrastructureExceptionReturns503(): void
    {
        $statusCode = $this->handler->handle(new InfrastructureException('DB down'));

        $this->assertSame(HttpStatus::ServiceUnavailable, $statusCode);
    }

    public function testHandleInfrastructureExceptionLogsError(): void
    {
        $this->handler->handle(new InfrastructureException('DB down'));

        $this->assertSame(LogLevel::ERROR, $this->logger->lastLevel);
    }

    public function testHandleFatalExceptionReturns500(): void
    {
        $statusCode = $this->handler->handle(new FatalException('Crash'));

        $this->assertSame(HttpStatus::InternalServerError, $statusCode);
    }

    public function testHandleFatalExceptionLogsCritical(): void
    {
        $this->handler->handle(new FatalException('Crash'));

        $this->assertSame(LogLevel::CRITICAL, $this->logger->lastLevel);
    }

    // ---------------------------------------------------------------
    // handle — overridden metadata
    // ---------------------------------------------------------------

    public function testHandleRespectsOverriddenStatusCode(): void
    {
        $exception  = new DomainException('Not found', HttpStatus::NotFound);
        $statusCode = $this->handler->handle($exception);

        $this->assertSame(HttpStatus::NotFound, $statusCode);
    }

    public function testHandleRespectsOverriddenLogLevel(): void
    {
        $exception = new DomainException('Escalated', 0, LogLevel::CRITICAL);
        $this->handler->handle($exception);

        $this->assertSame(LogLevel::CRITICAL, $this->logger->lastLevel);
    }

    // ---------------------------------------------------------------
    // handle — non-Sloop exceptions
    // ---------------------------------------------------------------

    public function testHandleNonSloopExceptionReturns500(): void
    {
        $statusCode = $this->handler->handle(new RuntimeException('Unknown'));

        $this->assertSame(HttpStatus::InternalServerError, $statusCode);
    }

    public function testHandleNonSloopExceptionLogsCritical(): void
    {
        $this->handler->handle(new RuntimeException('Unknown'));

        $this->assertSame(LogLevel::CRITICAL, $this->logger->lastLevel);
        $this->assertSame('Unknown', $this->logger->lastMessage);
    }

    // ---------------------------------------------------------------
    // handle — context includes exception and status code
    // ---------------------------------------------------------------

    public function testHandlePassesExceptionInContext(): void
    {
        $exception = new DomainException('Test');
        $this->handler->handle($exception);

        $this->assertSame($exception, $this->logger->lastContext['exception']);
    }

    public function testHandlePassesStatusCodeInContext(): void
    {
        $this->handler->handle(new InfrastructureException('Timeout'));

        $this->assertSame(HttpStatus::ServiceUnavailable, $this->logger->lastContext['status_code']);
    }

    // ---------------------------------------------------------------
    // resolveStatusCode
    // ---------------------------------------------------------------

    public function testResolveStatusCodeReturnsSloopExceptionCode(): void
    {
        $this->assertSame(HttpStatus::UnprocessableEntity, $this->handler->resolveStatusCode(new DomainException()));
        $this->assertSame(HttpStatus::ServiceUnavailable, $this->handler->resolveStatusCode(new InfrastructureException()));
        $this->assertSame(HttpStatus::InternalServerError, $this->handler->resolveStatusCode(new FatalException()));
    }

    public function testResolveStatusCodeReturns500ForNonSloopException(): void
    {
        $this->assertSame(HttpStatus::InternalServerError, $this->handler->resolveStatusCode(new RuntimeException()));
    }

    // ---------------------------------------------------------------
    // resolveLogLevel
    // ---------------------------------------------------------------

    public function testResolveLogLevelReturnsSloopExceptionLevel(): void
    {
        $this->assertSame(LogLevel::WARNING, $this->handler->resolveLogLevel(new DomainException()));
        $this->assertSame(LogLevel::ERROR, $this->handler->resolveLogLevel(new InfrastructureException()));
        $this->assertSame(LogLevel::CRITICAL, $this->handler->resolveLogLevel(new FatalException()));
    }

    public function testResolveLogLevelReturnsCriticalForNonSloopException(): void
    {
        $this->assertSame(LogLevel::CRITICAL, $this->handler->resolveLogLevel(new RuntimeException()));
    }
}

// ---------------------------------------------------------------
// Test fixture: minimal PSR-3 logger spy
// ---------------------------------------------------------------

final class SpyLogger implements LoggerInterface
{
    public string $lastLevel   = '';
    public string $lastMessage = '';

    /** @var array<mixed> */
    public array $lastContext = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->lastLevel   = \is_string($level) ? $level : '';
        $this->lastMessage = (string) $message;
        $this->lastContext = $context;
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
