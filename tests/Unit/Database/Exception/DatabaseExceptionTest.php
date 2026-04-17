<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Exception;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\Exception\ConstraintViolationException;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\DeadlockException;
use Sloop\Database\Exception\ForeignKeyViolationException;
use Sloop\Database\Exception\LockNotAvailableException;
use Sloop\Database\Exception\LockWaitTimeoutException;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Exception\SyntaxErrorException;
use Sloop\Database\Exception\UniqueConstraintViolationException;
use Sloop\Error\SloopException;
use Sloop\Http\HttpStatus;

final class DatabaseExceptionTest extends TestCase
{
    // -------------------------------------------------------
    // DatabaseException
    // -------------------------------------------------------

    /** @noinspection PhpConditionAlreadyCheckedInspection, UnnecessaryAssertionInspection */
    public function testDatabaseExceptionExtendsSloopException(): void
    {
        $e = new DatabaseConnectionException();

        $this->assertInstanceOf(SloopException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testDatabaseExceptionCarriesConnectionInfo(): void
    {
        $e = new DatabaseException(
            'connection refused',
            'main_rw',
            'HY000',
            2002,
        );

        $this->assertSame('connection refused', $e->getMessage());
        $this->assertSame('main_rw', $e->connectionName);
        $this->assertSame('HY000', $e->sqlState);
        $this->assertSame(2002, $e->driverCode);
    }

    public function testDatabaseExceptionDefaultsAreEmpty(): void
    {
        $e = new DatabaseException();

        $this->assertSame('', $e->getMessage());
        $this->assertSame('', $e->connectionName);
        $this->assertNull($e->sqlState);
        $this->assertNull($e->driverCode);
    }

    public function testDatabaseExceptionChainsPrevious(): void
    {
        $prev = new RuntimeException('pdo failure');
        $e    = new DatabaseException('wrapped', previous: $prev);

        $this->assertSame($prev, $e->getPrevious());
    }

    // -------------------------------------------------------
    // DatabaseConnectionException
    // -------------------------------------------------------

    public function testConnectionExceptionHas503StatusCode(): void
    {
        $e = new DatabaseConnectionException('cannot connect');

        $this->assertSame(HttpStatus::ServiceUnavailable, $e->statusCode);
    }

    public function testConnectionExceptionExtendsDatabaseException(): void
    {
        $this->assertInstanceOf(DatabaseException::class, new DatabaseConnectionException());
    }

    public function testConnectionExceptionCarriesConnectionInfoAndStatusCode(): void
    {
        $e = new DatabaseConnectionException(
            'max_connections reached',
            'main_rw',
            'HY000',
            1040,
        );

        $this->assertSame('max_connections reached', $e->getMessage());
        $this->assertSame('main_rw', $e->connectionName);
        $this->assertSame('HY000', $e->sqlState);
        $this->assertSame(1040, $e->driverCode);
        $this->assertSame(HttpStatus::ServiceUnavailable, $e->statusCode);
    }

    // -------------------------------------------------------
    // QueryException
    // -------------------------------------------------------

    /** @noinspection SqlResolve, SqlNoDataSourceInspection */
    public function testQueryExceptionCarriesSqlAndBindings(): void
    {
        $e = new QueryException(
            'query failed',
            'SELECT * FROM users WHERE id = ?',
            [42],
            'replica_01',
            '42S02',
            1146,
        );

        $this->assertSame('query failed', $e->getMessage());
        $this->assertSame('SELECT * FROM users WHERE id = ?', $e->sql);
        $this->assertSame([42], $e->bindings);
        $this->assertSame('replica_01', $e->connectionName);
        $this->assertSame('42S02', $e->sqlState);
        $this->assertSame(1146, $e->driverCode);
    }

    public function testQueryExceptionDefaultsAreEmpty(): void
    {
        $e = new QueryException();

        $this->assertSame('', $e->sql);
        $this->assertSame([], $e->bindings);
    }

    public function testQueryExceptionExtendsDatabaseException(): void
    {
        $this->assertInstanceOf(DatabaseException::class, new QueryException());
    }

    // -------------------------------------------------------
    // Inheritance hierarchy
    // -------------------------------------------------------

    /** @noinspection SqlResolve, SqlNoDataSourceInspection, PhpConditionAlreadyCheckedInspection, UnnecessaryAssertionInspection */
    public function testDeadlockExtendsQueryException(): void
    {
        $e = new DeadlockException('deadlock', 'UPDATE users SET n = 1 WHERE id = ?', [1], 'main_rw', '40001', 1213);

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertInstanceOf(DatabaseException::class, $e);
        $this->assertSame('40001', $e->sqlState);
        $this->assertSame(1213, $e->driverCode);
    }

    /** @noinspection SqlResolve, SqlNoDataSourceInspection, PhpConditionAlreadyCheckedInspection, UnnecessaryAssertionInspection */
    public function testLockWaitTimeoutExtendsQueryException(): void
    {
        $e = new LockWaitTimeoutException('timeout', 'SELECT * FROM users WHERE id = ? FOR UPDATE', [1], 'main_rw', 'HY000', 1205);

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(1205, $e->driverCode);
    }

    /** @noinspection SqlResolve, SqlNoDataSourceInspection, PhpConditionAlreadyCheckedInspection, UnnecessaryAssertionInspection */
    public function testLockNotAvailableExtendsQueryException(): void
    {
        $e = new LockNotAvailableException('nowait', 'SELECT * FROM users WHERE id = ? FOR UPDATE NOWAIT', [1], 'main_rw', 'HY000', 3572);

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(3572, $e->driverCode);
    }

    public function testConstraintViolationExtendsQueryException(): void
    {
        $this->assertInstanceOf(QueryException::class, new ConstraintViolationException());
    }

    /** @noinspection SqlResolve, SqlNoDataSourceInspection, PhpConditionAlreadyCheckedInspection, UnnecessaryAssertionInspection */
    public function testUniqueConstraintExtendsConstraintViolation(): void
    {
        $e = new UniqueConstraintViolationException(
            'Duplicate entry',
            'INSERT INTO users (name) VALUES (?)',
            ['alice'],
            'main_rw',
            '23000',
            1062,
        );

        $this->assertInstanceOf(ConstraintViolationException::class, $e);
        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(1062, $e->driverCode);
        $this->assertSame('INSERT INTO users (name) VALUES (?)', $e->sql);
    }

    /** @noinspection SqlResolve, SqlNoDataSourceInspection, PhpConditionAlreadyCheckedInspection, UnnecessaryAssertionInspection */
    public function testForeignKeyViolationExtendsConstraintViolation(): void
    {
        $e = new ForeignKeyViolationException('fk fail', 'DELETE FROM users WHERE id = ?', [1], 'main_rw', '23000', 1451);

        $this->assertInstanceOf(ConstraintViolationException::class, $e);
        $this->assertSame(1451, $e->driverCode);
    }

    /** @noinspection SqlResolve, SqlNoDataSourceInspection, PhpConditionAlreadyCheckedInspection, UnnecessaryAssertionInspection */
    public function testSyntaxErrorExtendsQueryException(): void
    {
        $e = new SyntaxErrorException('syntax error', 'INVALID SQL', [], 'main_rw', '42000', 1064);

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertSame(1064, $e->driverCode);
    }

    // -------------------------------------------------------
    // Catch hierarchy
    // -------------------------------------------------------

    public function testCatchDatabaseExceptionCatchesAllSubclasses(): void
    {
        $exceptions = [
            new DatabaseConnectionException(),
            new QueryException(),
            new DeadlockException(),
            new LockWaitTimeoutException(),
            new LockNotAvailableException(),
            new ConstraintViolationException(),
            new UniqueConstraintViolationException(),
            new ForeignKeyViolationException(),
            new SyntaxErrorException(),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(DatabaseException::class, $e, $e::class . ' must extend DatabaseException');
        }
    }

    public function testCatchQueryExceptionCatchesRetryableSubclasses(): void
    {
        $caught = [];

        foreach ([new DeadlockException(), new LockWaitTimeoutException()] as $e) {
            try {
                throw $e;
            } catch (QueryException $caught_e) {
                $caught[] = $caught_e::class;
            }
        }

        $this->assertSame([
            DeadlockException::class,
            LockWaitTimeoutException::class,
        ], $caught);
    }

    public function testCatchConstraintViolationCatchesBothSubclasses(): void
    {
        $caught = [];

        foreach ([new UniqueConstraintViolationException(), new ForeignKeyViolationException()] as $e) {
            try {
                throw $e;
            } catch (ConstraintViolationException $caught_e) {
                $caught[] = $caught_e::class;
            }
        }

        $this->assertSame([
            UniqueConstraintViolationException::class,
            ForeignKeyViolationException::class,
        ], $caught);
    }
}
