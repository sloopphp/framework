<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Exception;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Exception\ConstraintViolationException;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\DeadlockException;
use Sloop\Database\Exception\ExceptionFactory;
use Sloop\Database\Exception\ForeignKeyViolationException;
use Sloop\Database\Exception\LockNotAvailableException;
use Sloop\Database\Exception\LockWaitTimeoutException;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Exception\SyntaxErrorException;
use Sloop\Database\Exception\UniqueConstraintViolationException;

final class ExceptionFactoryTest extends TestCase
{
    private function makePDOException(string $message, string $sqlState, int $driverCode): PDOException
    {
        $e            = new PDOException($message);
        $e->errorInfo = [$sqlState, $driverCode, $message];

        return $e;
    }

    /**
     * @return array<string, array{int, string, class-string<DatabaseException>}>
     */
    public static function driverCodeProvider(): array
    {
        return [
            'deadlock (1213)'           => [1213, '40001', DeadlockException::class],
            'lock wait timeout (1205)'  => [1205, 'HY000', LockWaitTimeoutException::class],
            'lock not available (3572)' => [3572, 'HY000', LockNotAvailableException::class],
            'unique (1062)'             => [1062, '23000', UniqueConstraintViolationException::class],
            'fk parent missing (1452)'  => [1452, '23000', ForeignKeyViolationException::class],
            'fk on delete (1451)'       => [1451, '23000', ForeignKeyViolationException::class],
            'syntax (1064)'             => [1064, '42000', SyntaxErrorException::class],
        ];
    }

    /**
     * @param class-string<DatabaseException> $expected
     */
    #[DataProvider('driverCodeProvider')]
    public function testClassifiesByDriverCode(int $driverCode, string $sqlState, string $expected): void
    {
        $exception = $this->makePDOException('err', $sqlState, $driverCode);

        $result = ExceptionFactory::fromPDOException($exception);

        $this->assertInstanceOf($expected, $result);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function connectionErrorCodeProvider(): array
    {
        return [
            'access denied to db (1044)'    => [1044],
            'access denied for user (1045)' => [1045],
            'unknown database (1049)'       => [1049],
            'cant connect socket (2002)'    => [2002],
            'cant connect tcp (2003)'       => [2003],
            'unknown host (2005)'           => [2005],
            'gone away (2006)'              => [2006],
            'lost connection (2013)'        => [2013],
        ];
    }

    #[DataProvider('connectionErrorCodeProvider')]
    public function testClassifiesConnectionErrorsAsDatabaseConnectionException(int $driverCode): void
    {
        $exception = $this->makePDOException('connect failed', 'HY000', $driverCode);

        $result = ExceptionFactory::fromPDOException($exception);

        $this->assertInstanceOf(DatabaseConnectionException::class, $result);
    }

    public function testFallsBackToConstraintViolationForSqlState23(): void
    {
        $exception = $this->makePDOException('constraint', '23999', 9999);

        $result = ExceptionFactory::fromPDOException($exception);

        $this->assertInstanceOf(ConstraintViolationException::class, $result);
        $this->assertNotInstanceOf(UniqueConstraintViolationException::class, $result);
        $this->assertNotInstanceOf(ForeignKeyViolationException::class, $result);
    }

    public function testFallsBackToSyntaxErrorForSqlState42(): void
    {
        $exception = $this->makePDOException('syntax', '42S02', 8888);

        $result = ExceptionFactory::fromPDOException($exception);

        $this->assertInstanceOf(SyntaxErrorException::class, $result);
    }

    public function testFallsBackToConnectionForSqlState08(): void
    {
        $exception = $this->makePDOException('connection', '08001', 9999);

        $result = ExceptionFactory::fromPDOException($exception);

        $this->assertInstanceOf(DatabaseConnectionException::class, $result);
    }

    public function testFallsBackToQueryExceptionForUnknownClassification(): void
    {
        $exception = $this->makePDOException('weird error', 'HY000', 9999);

        $result = ExceptionFactory::fromPDOException($exception);

        $this->assertInstanceOf(QueryException::class, $result);
        $this->assertNotInstanceOf(DeadlockException::class, $result);
    }

    public function testReturnsQueryExceptionWhenErrorInfoAbsent(): void
    {
        $exception = new PDOException('no error info');

        $result = ExceptionFactory::fromPDOException($exception);

        $this->assertInstanceOf(QueryException::class, $result);
        $this->assertNull($result->sqlState);
        $this->assertNull($result->driverCode);
    }

    public function testPopulatesFieldsFromPDOException(): void
    {
        $exception = $this->makePDOException('duplicate', '23000', 1062);

        $result = ExceptionFactory::fromPDOException(
            $exception,
            'main_rw',
            'INSERT INTO users VALUES (?)',
            [1],
        );

        $this->assertInstanceOf(UniqueConstraintViolationException::class, $result);
        $this->assertSame('duplicate', $result->getMessage());
        $this->assertSame('main_rw', $result->connectionName);
        $this->assertSame('23000', $result->sqlState);
        $this->assertSame(1062, $result->driverCode);
        $this->assertSame('INSERT INTO users VALUES (?)', $result->sql);
        $this->assertSame([1], $result->bindings);
        $this->assertSame($exception, $result->getPrevious());
    }

    public function testPreservesOriginalExceptionAsPrevious(): void
    {
        $exception = $this->makePDOException('deadlock', '40001', 1213);

        $result = ExceptionFactory::fromPDOException($exception);

        $this->assertSame($exception, $result->getPrevious());
    }
}
