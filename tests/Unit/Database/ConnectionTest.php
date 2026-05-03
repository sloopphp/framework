<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PDO;
use Pdo\Sqlite;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\Connection;
use Sloop\Database\Dialect;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\DeadlockException;
use Sloop\Database\Exception\LockWaitTimeoutException;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\IsolationLevel;
use Sloop\Database\LoggingOptions;
use UnexpectedValueException;

/*
 * Policy: several tests below omit a trailing $this->fail() inside the try
 * block. When the code under test always throws on a given path, PHPStan
 * infers the call as never-return and flags the fail() as
 * deadCode.unreachable. coding-standards.md bans phpstan-ignore comments,
 * so we rely on PHPUnit's failOnRisky=true (a catch-less path with zero
 * assertions fails the test) to guard the "exception was not thrown" case.
 * Applies to testCommitRequiresActiveTransaction,
 * testRollbackRequiresActiveTransaction, and all testTransaction*-throws
 * tests.
 */
final class ConnectionTest extends TestCase
{
    private PDO $pdo;

    private Connection $connection;

    protected function setUp(): void
    {
        // Emulate MySQL's VERSION() via a user-defined SQLite function so we
        // can exercise Connection::dialect() without booting a real MySQL
        // server. Pdo\Sqlite::createFunction requires the driver-specific
        // subclass added in PHP 8.5.
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $sqlite->createFunction('version', static fn (): string => '10.11.11-MariaDB');

        $this->pdo        = $sqlite;
        $this->connection = new Connection($this->pdo, 'test_conn');
    }

    private function countUsers(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM users');
        $this->assertNotFalse($stmt);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $count = $row['c'] ?? 0;
        $this->assertIsInt($count);

        return $count;
    }

    private function attachLogger(Connection $connection, ?LoggingOptions $options = null): TestHandler
    {
        $handler = new TestHandler();
        $logger  = new Logger('database', [$handler]);
        $connection->setLogger($logger, $options ?? new LoggingOptions());

        return $handler;
    }

    // -------------------------------------------------------
    // open
    // -------------------------------------------------------

    public function testOpenReturnsUsableConnection(): void
    {
        // The PDO attributes sloop sets (ERRMODE_EXCEPTION, FETCH_ASSOC,
        // EMULATE_PREPARES=false, STRINGIFY_FETCHES=false) are verified by
        // their observable effects in the MySQL/MariaDB integration test.
        // Here we just confirm open() produces a working Connection.
        $connection = Connection::open('sqlite::memory:');
        $connection->statement('CREATE TABLE probe (id INTEGER PRIMARY KEY)');
        $connection->statement('INSERT INTO probe (id) VALUES (1)');

        $rows = $connection->query('SELECT id FROM probe')->toArray();
        $this->assertSame([['id' => 1]], $rows);
    }

    public function testOpenWrapsConnectionFailure(): void
    {
        $this->expectException(DatabaseException::class);

        Connection::open('mysql:host=127.0.0.1;port=1;dbname=nope', 'nope', 'nope', [
            PDO::ATTR_TIMEOUT => 1,
        ]);
    }

    // -------------------------------------------------------
    // query / statement
    // -------------------------------------------------------

    public function testQueryReturnsResultWithFetchedRows(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'alice'), (2, 'bob')");

        $result = $this->connection->query('SELECT id, name FROM users ORDER BY id');

        $this->assertSame(
            [['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']],
            $result->toArray(),
        );
    }

    public function testQueryAcceptsBindings(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'alice'), (2, 'bob')");

        $result = $this->connection->query('SELECT name FROM users WHERE id = ?', [2]);

        $this->assertSame([['name' => 'bob']], $result->toArray());
    }

    public function testQueryAcceptsNamedBindings(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'alice'), (2, 'bob')");

        $result = $this->connection->query(
            'SELECT name FROM users WHERE id = :id',
            ['id' => 2],
        );

        $this->assertSame([['name' => 'bob']], $result->toArray());
    }

    public function testQueryReturnsEmptyResultWhenNoRowsMatch(): void
    {
        $result = $this->connection->query('SELECT id FROM users');

        $this->assertCount(0, $result);
        $this->assertSame([], $result->toArray());
    }

    public function testQueryThrowsWhenPdoReturnsNonArrayRow(): void
    {
        // Defensive guard: FETCH_ASSOC contractually returns associative arrays,
        // but the throw is the type-narrowing fallback when a non-conformant
        // driver violates that contract.
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([['valid' => 1], 'invalid-row']);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $connection = new Connection($pdo, 'test');

        try {
            $connection->query('SELECT 1');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertSame(
                'PDO returned non-array row from FETCH_ASSOC',
                $e->getMessage(),
            );
        }
    }

    public function testStatementReturnsAffectedRowCount(): void
    {
        $affected = $this->connection->statement(
            'INSERT INTO users (id, name) VALUES (?, ?)',
            [1, 'alice'],
        );

        $this->assertSame(1, $affected);
    }

    public function testStatementReturnsZeroForDdl(): void
    {
        $affected = $this->connection->statement('CREATE TABLE tags (id INTEGER PRIMARY KEY)');

        $this->assertSame(0, $affected);
    }

    public function testStatementReturnsAffectedRowCountForUpdate(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'alice'), (2, 'bob')");

        $affected = $this->connection->statement(
            'UPDATE users SET name = ? WHERE id = ?',
            ['ALICE', 1],
        );

        $this->assertSame(1, $affected);
    }

    public function testStatementReturnsZeroWhenUpdateMatchesNoRows(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'alice')");

        $affected = $this->connection->statement(
            'UPDATE users SET name = ? WHERE id = ?',
            ['none', 999],
        );

        $this->assertSame(0, $affected);
    }

    public function testStatementWrapsSyntaxError(): void
    {
        $this->expectException(QueryException::class);

        $this->connection->statement('NOT VALID SQL');
    }

    public function testQueryWrapsSyntaxError(): void
    {
        $this->expectException(QueryException::class);

        $this->connection->query('SELECT FROM WHERE');
    }

    public function testWrappedExceptionCarriesConnectionName(): void
    {
        try {
            $this->connection->statement('NOT VALID SQL');
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('test_conn', $e->connectionName);
            $this->assertSame('NOT VALID SQL', $e->sql);
        }
    }

    // -------------------------------------------------------
    // begin / commit / rollback / inTransaction
    // -------------------------------------------------------

    public function testBeginCommitPersistsChanges(): void
    {
        $this->connection->begin();
        $this->connection->statement("INSERT INTO users (id, name) VALUES (1, 'alice')");
        $this->connection->commit();

        $this->assertSame(1, $this->countUsers());
    }

    public function testBeginRollbackDiscardsChanges(): void
    {
        $this->connection->begin();
        $this->connection->statement("INSERT INTO users (id, name) VALUES (1, 'alice')");
        $this->connection->rollback();

        $this->assertSame(0, $this->countUsers());
    }

    public function testInTransactionReflectsPdoState(): void
    {
        $this->assertFalse($this->connection->inTransaction());

        $this->connection->begin();
        $this->assertTrue($this->connection->inTransaction());

        $this->connection->commit();
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testBeginRejectsNesting(): void
    {
        $this->connection->begin();

        try {
            $this->connection->begin();
            $this->fail('Expected LogicException was not thrown');
        } catch (LogicException $e) {
            $this->assertSame(
                'Cannot begin a transaction while another is active (nesting is not supported).',
                $e->getMessage(),
            );
        } finally {
            $this->connection->rollback();
        }
    }

    public function testCommitRequiresActiveTransaction(): void
    {
        try {
            $this->connection->commit();
        } catch (LogicException $e) {
            $this->assertSame('Cannot commit: no active transaction.', $e->getMessage());
        }
    }

    public function testRollbackRequiresActiveTransaction(): void
    {
        try {
            $this->connection->rollback();
        } catch (LogicException $e) {
            $this->assertSame('Cannot rollback: no active transaction.', $e->getMessage());
        }
    }

    // -------------------------------------------------------
    // transaction()
    // -------------------------------------------------------

    public function testTransactionCommitsOnSuccess(): void
    {
        $result = $this->connection->transaction(function (Connection $db): string {
            $db->statement("INSERT INTO users (id, name) VALUES (1, 'alice')");

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, $this->countUsers());
    }

    public function testTransactionPassesSelfToCallback(): void
    {
        $receiver = new class () {
            public ?Connection $value = null;
        };

        $this->connection->transaction(function (Connection $db) use ($receiver): void {
            $receiver->value = $db;
        });

        $this->assertSame($this->connection, $receiver->value);
    }

    public function testTransactionRollsBackOnException(): void
    {
        try {
            $this->connection->transaction(function (Connection $db): void {
                $db->statement("INSERT INTO users (id, name) VALUES (1, 'alice')");

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertFalse($this->connection->inTransaction());
            $this->assertSame(0, $this->countUsers());
        }
    }

    public function testTransactionRetriesOnDeadlockUntilSuccess(): void
    {
        $counter = new class () {
            public int $value = 0;
        };

        $result = $this->connection->transaction(
            function () use ($counter): string {
                $counter->value++;
                if ($counter->value < 3) {
                    throw new DeadlockException('deadlock', '', [], 'test_conn', '40001', 1213);
                }

                return 'ok';
            },
            IsolationLevel::Default,
            5,
        );

        $this->assertSame(3, $counter->value);
        $this->assertSame('ok', $result);
    }

    public function testTransactionRetriesOnLockWaitTimeout(): void
    {
        $counter = new class () {
            public int $value = 0;
        };

        $result = $this->connection->transaction(
            function () use ($counter): string {
                $counter->value++;
                if ($counter->value < 2) {
                    throw new LockWaitTimeoutException('wait', '', [], 'test_conn', 'HY000', 1205);
                }

                return 'ok';
            },
            IsolationLevel::Default,
            3,
        );

        $this->assertSame(2, $counter->value);
        $this->assertSame('ok', $result);
    }

    public function testTransactionThrowsAfterExhaustingRetries(): void
    {
        $counter = new class () {
            public int $value = 0;
        };

        try {
            $this->connection->transaction(
                function () use ($counter): void {
                    $counter->value++;

                    throw new DeadlockException('repeat', '', [], 'test_conn', '40001', 1213);
                },
                IsolationLevel::Default,
                3,
            );
        } catch (DeadlockException $e) {
            $this->assertSame('repeat', $e->getMessage());
            $this->assertSame(3, $counter->value);
        }
    }

    public function testTransactionDoesNotRetryWhenMaxAttemptsIsOne(): void
    {
        $counter = new class () {
            public int $value = 0;
        };

        try {
            $this->connection->transaction(
                function () use ($counter): void {
                    $counter->value++;

                    throw new DeadlockException('once', '', [], 'test_conn', '40001', 1213);
                },
            );
        } catch (DeadlockException $e) {
            $this->assertSame('once', $e->getMessage());
            $this->assertSame(1, $counter->value);
        }
    }

    public function testTransactionDoesNotRetryNonRetryableExceptions(): void
    {
        $counter = new class () {
            public int $value = 0;
        };

        try {
            $this->connection->transaction(
                function () use ($counter): void {
                    $counter->value++;

                    throw new RuntimeException('once');
                },
                IsolationLevel::Default,
                5,
            );
        } catch (RuntimeException $e) {
            $this->assertSame('once', $e->getMessage());
            $this->assertSame(1, $counter->value);
        }
    }

    public function testTransactionRejectsNonPositiveMaxAttempts(): void
    {
        try {
            $this->connection->transaction(
                static fn (): string => 'ok',
                IsolationLevel::Default,
                0,
            );
        } catch (LogicException $e) {
            $this->assertSame('maxAttempts must be at least 1, got 0.', $e->getMessage());
        }
    }

    public function testTransactionRejectsNegativeBackoff(): void
    {
        try {
            $this->connection->transaction(
                static fn (): string => 'ok',
                IsolationLevel::Default,
                1,
                -5,
            );
        } catch (LogicException $e) {
            $this->assertSame('backoffMs must not be negative, got -5.', $e->getMessage());
        }
    }

    public function testTransactionRejectsNestedCall(): void
    {
        $this->connection->begin();

        try {
            $this->connection->transaction(static fn (): string => 'ok');
            $this->fail('Expected LogicException was not thrown');
        } catch (LogicException $e) {
            $this->assertSame(
                'Cannot start a nested transaction (savepoints are not supported).',
                $e->getMessage(),
            );
        } finally {
            $this->connection->rollback();
        }
    }

    // -------------------------------------------------------
    // dialect / serverVersion
    // -------------------------------------------------------

    public function testDialectDetectsFromServerVersion(): void
    {
        $this->assertSame(Dialect::MariaDB, $this->connection->dialect());
    }

    public function testServerVersionReturnsRawVersionString(): void
    {
        $this->assertSame('10.11.11-MariaDB', $this->connection->serverVersion());
    }

    public function testDialectCachesResultAcrossCalls(): void
    {
        $first  = $this->connection->dialect();
        $second = $this->connection->dialect();

        $this->assertSame($first, $second);
    }

    public function testServerVersionCachesResultAcrossCalls(): void
    {
        $first  = $this->connection->serverVersion();
        $second = $this->connection->serverVersion();

        $this->assertSame($first, $second);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function interleavedDialectAndServerVersionCallProvider(): array
    {
        return [
            'serverVersion first' => [['serverVersion', 'dialect', 'serverVersion', 'dialect']],
            'dialect first'       => [['dialect', 'serverVersion', 'dialect', 'serverVersion']],
        ];
    }

    /**
     * @param list<string> $callOrder
     */
    #[DataProvider('interleavedDialectAndServerVersionCallProvider')]
    public function testDialectAndServerVersionShareSingleSelectVersionQuery(array $callOrder): void
    {
        // Both getters are independently lazy via ??= but must share a single
        // `SELECT VERSION()` execution regardless of which is called first or
        // how many times callers interleave them.
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('10.11.11-MariaDB');

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('query')
            ->with('SELECT VERSION()')
            ->willReturn($statement);

        $connection = new Connection($pdo, 'test');

        foreach ($callOrder as $method) {
            if ($method === 'dialect') {
                $connection->dialect();
            } else {
                $connection->serverVersion();
            }
        }

        $this->assertSame(Dialect::MariaDB, $connection->dialect());
        $this->assertSame('10.11.11-MariaDB', $connection->serverVersion());
    }

    public function testDialectFallsBackToMysqlWhenVersionLacksMariadbMarker(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $sqlite->createFunction('version', static fn (): string => '8.0.37');

        $connection = new Connection($sqlite, 'mysql_like');

        $this->assertSame(Dialect::MySQL, $connection->dialect());
        $this->assertSame('8.0.37', $connection->serverVersion());
    }

    // -------------------------------------------------------
    // ping
    // -------------------------------------------------------

    public function testPingWrapsExecutionFailure(): void
    {
        // `DO` is MySQL/MariaDB-specific; SQLite rejects it as a syntax error
        // (SQLSTATE HY000 / driver code 1 → QueryException base class), which
        // exercises the same PDOException → DatabaseException wrap path that
        // production hits when the server has closed the connection.
        $this->expectException(QueryException::class);

        $this->connection->ping();
    }

    public function testPingFailureCarriesConnectionNameAndSql(): void
    {
        try {
            $this->connection->ping();
            $this->fail('Expected QueryException was not thrown');
        } catch (QueryException $e) {
            $this->assertSame('test_conn', $e->connectionName);
            $this->assertSame('DO 1', $e->sql);
        }
    }

    // -------------------------------------------------------
    // logging
    // -------------------------------------------------------

    public function testQueryLogsErrorOnFailure(): void
    {
        $handler = $this->attachLogger($this->connection);

        try {
            $this->connection->query('NOT VALID SQL');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // empty
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Error, $records[0]->level);
        $this->assertSame('NOT VALID SQL', $records[0]->context['sql']);
        $this->assertSame([], $records[0]->context['bindings']);
        $this->assertSame('test_conn', $records[0]->context['connection_name']);
        $this->assertArrayHasKey('sqlstate', $records[0]->context);
        $this->assertArrayHasKey('driver_code', $records[0]->context);
    }

    public function testStatementLogsErrorOnFailure(): void
    {
        $handler = $this->attachLogger($this->connection);

        try {
            $this->connection->statement('NOT VALID SQL');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // empty
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Error, $records[0]->level);
        $this->assertSame('NOT VALID SQL', $records[0]->context['sql']);
    }

    public function testFailureLogMessageMatchesException(): void
    {
        // Operators grep on the log message line, so the record's message
        // must match the thrown exception's message exactly (no prefix /
        // wrapper text).
        $handler = $this->attachLogger($this->connection);

        try {
            $this->connection->query('NOT VALID SQL');
        } catch (QueryException $e) {
            $records = $handler->getRecords();
            $this->assertCount(1, $records);
            $this->assertSame($e->getMessage(), $records[0]->message);
        }
    }

    public function testFailureLogRedactsBindingsWhenLogBindingsFalse(): void
    {
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logBindings: false),
        );

        try {
            $this->connection->statement('INSERT INTO unknown_table (name) VALUES (?)', ['secret']);
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // empty
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('[redacted]', $records[0]->context['bindings']);
    }

    public function testFailureLogIncludesDialectWhenAlreadyDetected(): void
    {
        // Trigger dialect detection first.
        $this->connection->dialect();

        $handler = $this->attachLogger($this->connection);

        try {
            $this->connection->query('NOT VALID SQL');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // empty
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('MariaDB', $records[0]->context['dialect']);
    }

    public function testFailureLogOmitsDialectWhenNotYetDetected(): void
    {
        $handler = $this->attachLogger($this->connection);

        try {
            $this->connection->query('NOT VALID SQL');
            $this->fail('Expected QueryException');
        } catch (QueryException) {
            // empty
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertArrayNotHasKey('dialect', $records[0]->context);
    }

    public function testQueryLogsSlowWarningWhenThresholdExceeded(): void
    {
        // Threshold 0ms ensures any non-zero elapsed time triggers the warning.
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(slowQueryThresholdMs: 0),
        );

        $this->connection->query('SELECT 1');

        $warnings = array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Warning,
        );
        $this->assertCount(1, $warnings);
        $warning = array_values($warnings)[0];
        $this->assertSame('slow query', $warning->message);
        $this->assertSame('SELECT 1', $warning->context['sql']);
        $this->assertArrayHasKey('elapsed_ms', $warning->context);
    }

    public function testStatementDoesNotLogSlowWarning(): void
    {
        // Slow threshold is intentionally limited to SELECT (query()) per design.
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(slowQueryThresholdMs: 0),
        );

        $this->connection->statement(
            'INSERT INTO users (id, name) VALUES (?, ?)',
            [1, 'alice'],
        );

        $warnings = array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Warning,
        );
        $this->assertCount(0, $warnings);
    }

    public function testQueryLogsDebugWhenLogAllQueriesEnabled(): void
    {
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logAllQueries: true),
        );

        $this->connection->query('SELECT 1');

        $debugs = array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Debug,
        );
        $this->assertCount(1, $debugs);
        $debug = array_values($debugs)[0];
        $this->assertSame('query executed', $debug->message);
        $this->assertSame('SELECT 1', $debug->context['sql']);
        $this->assertArrayHasKey('elapsed_ms', $debug->context);
    }

    public function testStatementLogsDebugWhenLogAllQueriesEnabled(): void
    {
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logAllQueries: true),
        );

        $this->connection->statement(
            'INSERT INTO users (id, name) VALUES (?, ?)',
            [1, 'alice'],
        );

        $debugs = array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Debug,
        );
        $this->assertCount(1, $debugs);
    }

    public function testSuccessLogIsSilentWhenAllOptionsAreOff(): void
    {
        $handler = $this->attachLogger($this->connection);

        $this->connection->query('SELECT 1');
        $this->connection->statement('CREATE TABLE probe (id INTEGER)');

        $this->assertCount(0, $handler->getRecords());
    }

    public function testNoLoggingWhenLoggerNotSet(): void
    {
        // Regression: Connection without setLogger() must not crash on the log path.
        // The catch-block assertion confirms QueryException was raised (and no other
        // exception slipped through from a logger reference on a null logger).
        try {
            $this->connection->query('NOT VALID SQL');
        } catch (QueryException $e) {
            $this->assertSame('test_conn', $e->connectionName);
        }
    }

    public function testSlowWarningOverridesLogAllQueriesWhenBothApply(): void
    {
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logAllQueries: true, slowQueryThresholdMs: 0),
        );

        $this->connection->query('SELECT 1');

        // Only one record should be emitted: slow warning takes precedence over debug.
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Warning, $records[0]->level);
    }

    public function testTransactionRollbackFailureLogsWarning(): void
    {
        // Mock PDO so beginTransaction succeeds but rollBack throws — exercises
        // rollbackAndNormalize's catch path, which should warn-log without
        // surfacing the rollback error to the caller.
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturnOnConsecutiveCalls(false, false, true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willThrowException(new \PDOException('rollback broken'));

        $connection = new Connection($pdo, 'rollback_test');
        $handler    = $this->attachLogger($connection);

        try {
            $connection->transaction(static function (): void {
                throw new RuntimeException('callback boom');
            });
        } catch (RuntimeException) {
            // empty
        }

        $warnings = array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Warning,
        );
        $this->assertCount(1, $warnings);
        $warning = array_values($warnings)[0];
        $this->assertSame('rollback failed during exception unwind', $warning->message);
        $this->assertSame('rollback broken', $warning->context['rollback_error']);
        $this->assertSame(RuntimeException::class, $warning->context['original_exception']);
        $this->assertSame('callback boom', $warning->context['original_message']);
        $this->assertSame('rollback_test', $warning->context['connection_name']);
    }
}
