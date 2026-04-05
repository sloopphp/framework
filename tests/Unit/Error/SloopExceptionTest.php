<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Error;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Sloop\Error\DomainException;
use Sloop\Error\FatalException;
use Sloop\Error\InfrastructureException;
use Sloop\Error\SloopException;
use Sloop\Http\HttpStatus;

final class SloopExceptionTest extends TestCase
{
    // ---------------------------------------------------------------
    // DomainException defaults
    // ---------------------------------------------------------------

    public function testDomainExceptionHasDefaultStatusCode422(): void
    {
        $exception = new DomainException('Validation failed');

        $this->assertSame(HttpStatus::UnprocessableEntity, $exception->statusCode);
    }

    public function testDomainExceptionHasDefaultLogLevelWarning(): void
    {
        $exception = new DomainException('Validation failed');

        $this->assertSame(LogLevel::WARNING, $exception->logLevel);
    }

    // ---------------------------------------------------------------
    // InfrastructureException defaults
    // ---------------------------------------------------------------

    public function testInfrastructureExceptionHasDefaultStatusCode503(): void
    {
        $exception = new InfrastructureException('Database unavailable');

        $this->assertSame(HttpStatus::ServiceUnavailable, $exception->statusCode);
    }

    public function testInfrastructureExceptionHasDefaultLogLevelError(): void
    {
        $exception = new InfrastructureException('Database unavailable');

        $this->assertSame(LogLevel::ERROR, $exception->logLevel);
    }

    // ---------------------------------------------------------------
    // FatalException defaults
    // ---------------------------------------------------------------

    public function testFatalExceptionHasDefaultStatusCode500(): void
    {
        $exception = new FatalException('Unrecoverable error');

        $this->assertSame(HttpStatus::InternalServerError, $exception->statusCode);
    }

    public function testFatalExceptionHasDefaultLogLevelCritical(): void
    {
        $exception = new FatalException('Unrecoverable error');

        $this->assertSame(LogLevel::CRITICAL, $exception->logLevel);
    }

    // ---------------------------------------------------------------
    // Status code override
    // ---------------------------------------------------------------

    public function testDomainExceptionStatusCodeCanBeOverridden(): void
    {
        $exception = new DomainException('Not found', HttpStatus::NotFound);

        $this->assertSame(HttpStatus::NotFound, $exception->statusCode);
    }

    public function testInfrastructureExceptionStatusCodeCanBeOverridden(): void
    {
        $exception = new InfrastructureException('Rate limited', HttpStatus::TooManyRequests);

        $this->assertSame(HttpStatus::TooManyRequests, $exception->statusCode);
    }

    public function testFatalExceptionStatusCodeCanBeOverridden(): void
    {
        $exception = new FatalException('Unavailable', HttpStatus::ServiceUnavailable);

        $this->assertSame(HttpStatus::ServiceUnavailable, $exception->statusCode);
    }

    public function testZeroStatusCodeUsesDefault(): void
    {
        $exception = new DomainException('Validation failed', 0);

        $this->assertSame(HttpStatus::UnprocessableEntity, $exception->statusCode);
    }

    public function testNegativeStatusCodeUsesDefault(): void
    {
        $exception = new DomainException('Validation failed', -1);

        $this->assertSame(HttpStatus::UnprocessableEntity, $exception->statusCode);
    }

    // ---------------------------------------------------------------
    // Log level override
    // ---------------------------------------------------------------

    public function testDomainExceptionLogLevelCanBeOverridden(): void
    {
        $exception = new DomainException('Critical domain issue', 0, LogLevel::CRITICAL);

        $this->assertSame(LogLevel::CRITICAL, $exception->logLevel);
    }

    public function testInfrastructureExceptionLogLevelCanBeOverridden(): void
    {
        $exception = new InfrastructureException('Downgraded', 0, LogLevel::WARNING);

        $this->assertSame(LogLevel::WARNING, $exception->logLevel);
    }

    public function testFatalExceptionLogLevelCanBeOverridden(): void
    {
        $exception = new FatalException('Downgraded', 0, LogLevel::ERROR);

        $this->assertSame(LogLevel::ERROR, $exception->logLevel);
    }

    public function testEmptyLogLevelUsesDefault(): void
    {
        $exception = new InfrastructureException('Timeout', 0, '');

        $this->assertSame(LogLevel::ERROR, $exception->logLevel);
    }

    // ---------------------------------------------------------------
    // Both overrides
    // ---------------------------------------------------------------

    public function testBothStatusCodeAndLogLevelCanBeOverridden(): void
    {
        $exception = new InfrastructureException('Rate limited', HttpStatus::TooManyRequests, LogLevel::WARNING);

        $this->assertSame(HttpStatus::TooManyRequests, $exception->statusCode);
        $this->assertSame(LogLevel::WARNING, $exception->logLevel);
    }

    // ---------------------------------------------------------------
    // Message and previous exception
    // ---------------------------------------------------------------

    public function testDomainExceptionMessageIsPreserved(): void
    {
        $exception = new DomainException('User not found');

        $this->assertSame('User not found', $exception->getMessage());
    }

    public function testInfrastructureExceptionMessageIsPreserved(): void
    {
        $exception = new InfrastructureException('Connection refused');

        $this->assertSame('Connection refused', $exception->getMessage());
    }

    public function testFatalExceptionMessageIsPreserved(): void
    {
        $exception = new FatalException('Out of memory');

        $this->assertSame('Out of memory', $exception->getMessage());
    }

    public function testPreviousExceptionIsChained(): void
    {
        $previous  = new RuntimeException('Original error');
        $exception = new InfrastructureException('Wrapped', 0, '', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    // ---------------------------------------------------------------
    // Default message
    // ---------------------------------------------------------------

    public function testDefaultMessageIsEmptyString(): void
    {
        $exception = new DomainException();

        $this->assertSame('', $exception->getMessage());
    }

    // ---------------------------------------------------------------
    // Previous exception independence
    // ---------------------------------------------------------------

    public function testPreviousExceptionDoesNotAffectStatusCode(): void
    {
        $previous  = new InfrastructureException('DB down');
        $exception = new DomainException('Wrapped', 0, '', $previous);

        $this->assertSame(HttpStatus::UnprocessableEntity, $exception->statusCode);
        $this->assertSame(HttpStatus::ServiceUnavailable, $previous->statusCode);
    }

    public function testPreviousExceptionDoesNotAffectLogLevel(): void
    {
        $previous  = new FatalException('Crash');
        $exception = new DomainException('Wrapped', 0, '', $previous);

        $this->assertSame(LogLevel::WARNING, $exception->logLevel);
        $this->assertSame(LogLevel::CRITICAL, $previous->logLevel);
    }

    // ---------------------------------------------------------------
    // Inheritance
    // ---------------------------------------------------------------

    public function testAllExceptionsExtendSloopException(): void
    {
        $this->assertInstanceOf(SloopException::class, new DomainException());
        $this->assertInstanceOf(SloopException::class, new InfrastructureException());
        $this->assertInstanceOf(SloopException::class, new FatalException());
    }

    public function testSloopExceptionExtendsRuntimeException(): void
    {
        $parents = class_parents(DomainException::class);

        $this->assertContains(RuntimeException::class, $parents);
    }
}
