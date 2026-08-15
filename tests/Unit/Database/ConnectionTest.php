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
use PHPUnit\Framework\MockObject\Stub;
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
use Sloop\Tests\Support\ThrowsAssertions;
use UnexpectedValueException;

final class ConnectionTest extends TestCase
{
    use ThrowsAssertions;

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

    private function scriptVersionQuery(Stub&PDO $pdo, string $versionString): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($versionString);

        // The SQL is asserted inside the callback rather than through
        // `with(...)`: PHPUnit 13 deprecates `with*()` unless it is paired with
        // `expects(...)`, and the only invocation count that does not constrain
        // how often the stub is called — `any()` — is deprecated as well. A
        // callback keeps the argument assertion without tying the stub to an
        // invocation count.
        $pdo->method('query')->willReturnCallback(function (string $sql) use ($statement): PDOStatement {
            $this->assertSame('SELECT VERSION()', $sql);

            return $statement;
        });
    }

    private function scriptedSelectStatement(): PDOStatement
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        return $stmt;
    }

    private function scriptedDmlStatement(): PDOStatement
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);

        return $stmt;
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

        $rows = $connection->query('SELECT id FROM probe')->asArray();
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
            $result->asArray(),
        );
    }

    public function testQueryAcceptsBindings(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'alice'), (2, 'bob')");

        $result = $this->connection->query('SELECT name FROM users WHERE id = ?', [2]);

        $this->assertSame([['name' => 'bob']], $result->asArray());
    }

    public function testQueryAcceptsNamedBindings(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'alice'), (2, 'bob')");

        $result = $this->connection->query(
            'SELECT name FROM users WHERE id = :id',
            ['id' => 2],
        );

        $this->assertSame([['name' => 'bob']], $result->asArray());
    }

    public function testQueryReturnsEmptyResultWhenNoRowsMatch(): void
    {
        $result = $this->connection->query('SELECT id FROM users');

        $this->assertCount(0, $result);
        $this->assertSame([], $result->asArray());
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

        $e = $this->assertThrows(UnexpectedValueException::class, static fn () => $connection->query('SELECT 1'));
        $this->assertSame(
            'PDO returned non-array row from FETCH_ASSOC',
            $e->getMessage(),
        );
    }

    public function testQueryThrowsWhenPdoReturnsAValueOutsideTheDriverContract(): void
    {
        // With EMULATE_PREPARES and STRINGIFY_FETCHES off the drivers return
        // only int/float/string/null. Letting anything else through would widen
        // Result's value type for every caller.
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([['flag' => true]]);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $connection = new Connection($pdo, 'test');

        $e = $this->assertThrows(UnexpectedValueException::class, static fn () => $connection->query('SELECT 1'));
        $this->assertSame(
            'PDO returned an unsupported value type for column "flag": bool',
            $e->getMessage(),
        );
    }

    public function testQueryReturnsAnIntColumnNameForANumericColumnLabel(): void
    {
        // Result declares its keys as array-key rather than string because of
        // this: the driver hands back the label "1", and PHP turns a numeric
        // string key into an int on assignment. Pinning it here so the type
        // declaration keeps a reason attached to it.
        $rows = $this->connection->query('SELECT 1')->asArray();

        $this->assertSame([[1 => 1]], $rows);
        $this->assertSame([1], array_keys($rows[0]));
    }

    public function testQueryPassesTheFourContractedValueTypesThrough(): void
    {
        // The negative case above only shows the guard fires; this shows it does
        // not fire on the types the drivers do return, null included.
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([
            ['i' => 1, 'f' => 1.5, 's' => 'text', 'n' => null],
        ]);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $rows = new Connection($pdo, 'test')->query('SELECT 1')->asArray();

        $this->assertSame([['i' => 1, 'f' => 1.5, 's' => 'text', 'n' => null]], $rows);
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
        $e = $this->assertThrows(QueryException::class, fn () => $this->connection->statement('NOT VALID SQL'));
        $this->assertSame('test_conn', $e->connectionName);
        $this->assertSame('NOT VALID SQL', $e->sql);
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
        $e = $this->assertThrows(LogicException::class, fn () => $this->connection->commit());
        $this->assertSame('Cannot commit: no active transaction.', $e->getMessage());
    }

    public function testRollbackRequiresActiveTransaction(): void
    {
        $e = $this->assertThrows(LogicException::class, fn () => $this->connection->rollback());
        $this->assertSame('Cannot rollback: no active transaction.', $e->getMessage());
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
        // The transaction must actually be committed, not merely visible from
        // inside a still-open transaction on the same connection.
        $this->assertFalse($this->connection->inTransaction());
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
        $e = $this->assertThrows(
            RuntimeException::class,
            fn () => $this->connection->transaction(function (Connection $db): void {
                $db->statement("INSERT INTO users (id, name) VALUES (1, 'alice')");

                throw new RuntimeException('boom');
            }),
        );
        $this->assertSame('boom', $e->getMessage());
        $this->assertFalse($this->connection->inTransaction());
        $this->assertSame(0, $this->countUsers());
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

        $e = $this->assertThrows(
            DeadlockException::class,
            fn () => $this->connection->transaction(
                function () use ($counter): void {
                    $counter->value++;

                    throw new DeadlockException('repeat', '', [], 'test_conn', '40001', 1213);
                },
                IsolationLevel::Default,
                3,
            ),
        );
        $this->assertSame('repeat', $e->getMessage());
        $this->assertSame(3, $counter->value);
    }

    public function testTransactionRetrySleepsForConfiguredBackoff(): void
    {
        $counter = new class () {
            public int $value = 0;
        };

        $start = hrtime(true);

        $this->connection->transaction(
            function () use ($counter): string {
                $counter->value++;
                if ($counter->value < 3) {
                    throw new DeadlockException('deadlock', '', [], 'test_conn', '40001', 1213);
                }

                return 'ok';
            },
            IsolationLevel::Default,
            3,
            1,
        );

        // 2 retries × 1ms backoff: usleep guarantees at least the requested
        // duration, so the elapsed time gives a deterministic lower bound.
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $this->assertSame(3, $counter->value);
        $this->assertGreaterThanOrEqual(2, $elapsedMs);
    }

    public function testTransactionDoesNotRetryWhenRollbackLeavesTransactionOpen(): void
    {
        // When ROLLBACK itself fails (e.g. the connection dropped), the PDO
        // still reports an active transaction. Retrying would hit begin()'s
        // nested-transaction guard and mask the original DeadlockException
        // with a LogicException, so the retry must be abandoned instead.
        $pdo = $this->createStub(PDO::class);
        // Call order: transaction() guard → begin() guard → rollbackAndNormalize
        // check → post-rollback retry check (the rollback failed, so the
        // transaction is still reported as active from the third call on).
        $pdo->method('inTransaction')->willReturn(false, false, true, true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willThrowException(new \PDOException('server has gone away'));

        $connection = new Connection($pdo, 'test_conn');

        $this->expectException(DeadlockException::class);
        $this->expectExceptionMessageIsOrContains('deadlock');

        $connection->transaction(
            function (): void {
                throw new DeadlockException('deadlock', '', [], 'test_conn', '40001', 1213);
            },
            IsolationLevel::Default,
            3,
        );
    }

    public function testTransactionDoesNotRetryWhenMaxAttemptsIsOne(): void
    {
        $counter = new class () {
            public int $value = 0;
        };

        $e = $this->assertThrows(
            DeadlockException::class,
            fn () => $this->connection->transaction(
                function () use ($counter): void {
                    $counter->value++;

                    throw new DeadlockException('once', '', [], 'test_conn', '40001', 1213);
                },
            ),
        );
        $this->assertSame('once', $e->getMessage());
        $this->assertSame(1, $counter->value);
    }

    public function testTransactionDoesNotRetryNonRetryableExceptions(): void
    {
        $counter = new class () {
            public int $value = 0;
        };

        $e = $this->assertThrows(
            RuntimeException::class,
            fn () => $this->connection->transaction(
                function () use ($counter): void {
                    $counter->value++;

                    throw new RuntimeException('once');
                },
                IsolationLevel::Default,
                5,
            ),
        );
        $this->assertSame('once', $e->getMessage());
        $this->assertSame(1, $counter->value);
    }

    public function testTransactionRejectsNonPositiveMaxAttempts(): void
    {
        $e = $this->assertThrows(
            LogicException::class,
            fn () => $this->connection->transaction(
                static fn (): string => 'ok',
                IsolationLevel::Default,
                0,
            ),
        );
        $this->assertSame('maxAttempts must be at least 1, got 0.', $e->getMessage());
    }

    public function testTransactionRejectsNegativeBackoff(): void
    {
        $e = $this->assertThrows(
            LogicException::class,
            fn () => $this->connection->transaction(
                static fn (): string => 'ok',
                IsolationLevel::Default,
                1,
                -5,
            ),
        );
        $this->assertSame('backoffMs must not be negative, got -5.', $e->getMessage());
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
        $e = $this->assertThrows(QueryException::class, fn () => $this->connection->ping());
        $this->assertSame('test_conn', $e->connectionName);
        $this->assertSame('DO 1', $e->sql);
    }

    // -------------------------------------------------------
    // logging
    // -------------------------------------------------------

    public function testQueryLogsErrorOnFailure(): void
    {
        $handler = $this->attachLogger($this->connection);

        // empty
        $this->assertThrows(QueryException::class, fn () => $this->connection->query('NOT VALID SQL'));

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

        // empty
        $this->assertThrows(QueryException::class, fn () => $this->connection->statement('NOT VALID SQL'));

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

        $e       = $this->assertThrows(QueryException::class, fn () => $this->connection->query('NOT VALID SQL'));
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame($e->getMessage(), $records[0]->message);
    }

    public function testFailureLogRedactsBindingsWhenLogBindingsFalse(): void
    {
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logBindings: false),
        );

        // empty
        $this->assertThrows(
            QueryException::class,
            fn () => $this->connection->statement('INSERT INTO unknown_table (name) VALUES (?)', ['secret']),
        );

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('[redacted]', $records[0]->context['bindings']);
    }

    public function testFailureLogIncludesDialectWhenAlreadyDetected(): void
    {
        // Trigger dialect detection first.
        $this->connection->dialect();

        $handler = $this->attachLogger($this->connection);

        // empty
        $this->assertThrows(QueryException::class, fn () => $this->connection->query('NOT VALID SQL'));

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('MariaDB', $records[0]->context['dialect']);
    }

    public function testFailureLogOmitsDialectWhenNotYetDetected(): void
    {
        $handler = $this->attachLogger($this->connection);

        // empty
        $this->assertThrows(QueryException::class, fn () => $this->connection->query('NOT VALID SQL'));

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

    public function testSlowWarningRedactsBindingsAndCarriesConnectionName(): void
    {
        // buildLogContext() is shared with the failure path, where redaction
        // and connection_name are already asserted. Sharing an implementation
        // is not the same as sharing coverage: the success path reaches it
        // through a different caller, so assert it here too.
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logBindings: false, slowQueryThresholdMs: 0),
        );

        $this->connection->query('SELECT * FROM users WHERE id = ?', [1]);

        $warnings = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Warning,
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('[redacted]', $warnings[0]->context['bindings']);
        $this->assertSame('test_conn', $warnings[0]->context['connection_name']);
    }

    public function testDebugLogRedactsBindingsAndCarriesConnectionName(): void
    {
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logBindings: false, logAllQueries: true),
        );

        $this->connection->query('SELECT * FROM users WHERE id = ?', [1]);

        $debugs = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Debug,
        ));
        $this->assertCount(1, $debugs);
        $this->assertSame('[redacted]', $debugs[0]->context['bindings']);
        $this->assertSame('test_conn', $debugs[0]->context['connection_name']);
    }

    public function testDebugLogIncludesDialectOnceItHasBeenDetected(): void
    {
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logAllQueries: true),
        );

        $this->connection->query('SELECT 1');
        $this->connection->dialect();
        $this->connection->query('SELECT 1');

        $debugs = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Debug,
        ));
        $this->assertCount(2, $debugs);
        // The first query ran before detection, so paying for a SELECT
        // VERSION() just to fill the log field is exactly what the guard
        // avoids; the second one gets the field for free.
        $this->assertArrayNotHasKey('dialect', $debugs[0]->context);
        $this->assertSame('MariaDB', $debugs[1]->context['dialect']);
    }

    public function testStatementDebugLogCarriesSqlAndBindings(): void
    {
        // The existing statement() debug test asserts the record count only,
        // so the context could be empty and it would still pass.
        $handler = $this->attachLogger(
            $this->connection,
            new LoggingOptions(logAllQueries: true),
        );

        $this->connection->statement(
            'INSERT INTO users (id, name) VALUES (?, ?)',
            [1, 'alice'],
        );

        $debugs = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->level === Level::Debug,
        ));
        $this->assertCount(1, $debugs);
        $this->assertSame('INSERT INTO users (id, name) VALUES (?, ?)', $debugs[0]->context['sql']);
        $this->assertSame([1, 'alice'], $debugs[0]->context['bindings']);
        $this->assertSame('test_conn', $debugs[0]->context['connection_name']);
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
        $e = $this->assertThrows(QueryException::class, fn () => $this->connection->query('NOT VALID SQL'));
        $this->assertSame('test_conn', $e->connectionName);
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

        // empty
        $this->assertThrows(
            RuntimeException::class,
            static fn () => $connection->transaction(static function (): void {
                throw new RuntimeException('callback boom');
            }),
        );

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

    // -------------------------------------------------------
    // query timeout (lazy apply)
    // -------------------------------------------------------

    public function testQueryTimeoutNotIssuedUntilFirstQuery(): void
    {
        // setQueryTimeoutMs alone must not touch the server. Lazy apply is the
        // contract — the first query() / statement() is what triggers SET SESSION.
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $pdo->expects($this->never())->method('query');

        $connection = new Connection($pdo, 'test');
        $connection->setQueryTimeoutMs(5000);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function dialectAndTimeoutProvider(): array
    {
        return [
            'MySQL'                  => ['8.0.37',           5000, 'SET SESSION max_execution_time = 5000'],
            'MariaDB fractional sec' => ['10.11.11-MariaDB', 1500, 'SET SESSION max_statement_time = 1.500'],
            'MariaDB minimum 1ms'    => ['10.11.11-MariaDB', 1,    'SET SESSION max_statement_time = 0.001'],
        ];
    }

    #[DataProvider('dialectAndTimeoutProvider')]
    public function testQueryTimeoutAppliesDialectSpecificSetSession(
        string $versionString,
        int $timeoutMs,
        string $expectedSetSessionSql,
    ): void {
        // Each dialect emits a distinct SET SESSION statement. MariaDB also
        // converts ms → fractional seconds with three decimal places so a 1ms
        // boundary value surfaces as 0.001 rather than truncating to 0.
        $pdo = $this->createMock(PDO::class);
        $this->scriptVersionQuery($pdo, $versionString);
        $pdo->expects($this->once())
            ->method('exec')
            ->with($expectedSetSessionSql)
            ->willReturn(0);
        $pdo->method('prepare')->willReturn($this->scriptedSelectStatement());

        $connection = new Connection($pdo, 'test');
        $connection->setQueryTimeoutMs($timeoutMs);

        $connection->query('SELECT 1');
    }

    public function testQueryTimeoutIsIssuedOnlyOnceAcrossMultipleQueries(): void
    {
        // The applied flag must short-circuit subsequent queries — re-issuing
        // SET SESSION every time would burn a round trip per query.
        $pdo = $this->createMock(PDO::class);
        $this->scriptVersionQuery($pdo, '8.0.37');
        $pdo->expects($this->once())
            ->method('exec')
            ->with('SET SESSION max_execution_time = 5000')
            ->willReturn(0);
        $pdo->method('prepare')->willReturn($this->scriptedSelectStatement());

        $connection = new Connection($pdo, 'test');
        $connection->setQueryTimeoutMs(5000);

        $connection->query('SELECT 1');
        $connection->query('SELECT 2');
        $connection->query('SELECT 3');
    }

    public function testQueryTimeoutIsSkippedWhenUnset(): void
    {
        // No setQueryTimeoutMs call — applyQueryTimeoutIfPending must not
        // touch exec() and must not run SELECT VERSION() (no dialect probe
        // when the timeout is unset).
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $pdo->expects($this->never())->method('query');
        $pdo->method('prepare')->willReturn($this->scriptedSelectStatement());

        $connection = new Connection($pdo, 'test');

        $connection->query('SELECT 1');
    }

    public function testQueryTimeoutIsSkippedWhenSetToNull(): void
    {
        // setQueryTimeoutMs(null) explicitly disables the lazy apply path.
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $pdo->expects($this->never())->method('query');
        $pdo->method('prepare')->willReturn($this->scriptedSelectStatement());

        $connection = new Connection($pdo, 'test');
        $connection->setQueryTimeoutMs(null);

        $connection->query('SELECT 1');
    }

    public function testStatementAlsoTriggersQueryTimeoutApply(): void
    {
        // statement() shares the lazy apply hook with query() so DML/DDL
        // workloads that never run a SELECT still get the timeout configured.
        $pdo = $this->createMock(PDO::class);
        $this->scriptVersionQuery($pdo, '8.0.37');
        $pdo->expects($this->once())
            ->method('exec')
            ->with('SET SESSION max_execution_time = 5000')
            ->willReturn(0);
        $pdo->method('prepare')->willReturn($this->scriptedDmlStatement());

        $connection = new Connection($pdo, 'test');
        $connection->setQueryTimeoutMs(5000);

        $connection->statement('INSERT INTO probe (id) VALUES (1)');
    }

    public function testQueryTimeoutFailureLogsErrorAndPropagates(): void
    {
        // SET SESSION failure must surface to the caller (the timeout did not
        // apply, the connection is misconfigured). When a logger is wired in,
        // the failure also lands as `error` level so operators see it.
        $pdo = $this->createStub(PDO::class);
        $this->scriptVersionQuery($pdo, '8.0.37');
        $pdoException            = new \PDOException('access denied');
        $pdoException->errorInfo = ['42000', 1064, 'access denied'];
        $pdo->method('exec')->willThrowException($pdoException);

        $connection = new Connection($pdo, 'test');
        $handler    = $this->attachLogger($connection);
        $connection->setQueryTimeoutMs(5000);

        $e = $this->assertThrows(DatabaseException::class, static fn () => $connection->query('SELECT 1'));
        $this->assertStringContainsString('access denied', $e->getMessage());

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Error, $records[0]->level);
        // The driver's own message and error identifiers are what make this
        // record actionable, so pin the whole message and every context key
        // rather than just the prefix.
        $this->assertSame('failed to apply query timeout: access denied', $records[0]->message);
        $this->assertSame('42000', $records[0]->context['sqlstate']);
        $this->assertSame(1064, $records[0]->context['driver_code']);
        $this->assertSame('test', $records[0]->context['connection_name']);
    }

    public function testServerVersionNormalizesANonStringDriverValueToString(): void
    {
        // PDO::fetchColumn is typed mixed; a driver handing back an int must
        // not leak that type through serverVersion()'s string contract.
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(8);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($statement);

        $connection = new Connection($pdo, 'test');

        $this->assertSame('8', $connection->serverVersion());
    }

    public function testQueryTimeoutFailureRetriesSetSessionOnNextQuery(): void
    {
        // queryTimeoutApplied stays false after a failed SET SESSION so the
        // next query attempts it again rather than running unprotected with
        // a silently-skipped timeout.
        $pdo = $this->createMock(PDO::class);
        $this->scriptVersionQuery($pdo, '8.0.37');
        $pdo->expects($this->exactly(2))
            ->method('exec')
            ->willThrowException(new \PDOException('access denied'));

        $connection = new Connection($pdo, 'test');
        $connection->setQueryTimeoutMs(5000);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            // expected — both attempts must surface as DatabaseException
            $this->assertThrows(DatabaseException::class, static fn () => $connection->query('SELECT 1'));
        }
    }

    public function testQueryTimeoutFailureWithoutLoggerStillPropagates(): void
    {
        // applyQueryTimeoutIfPending uses `$this->logger?->error(...)` to log
        // the failure; with no logger configured, the null-safe call must
        // simply do nothing and the DatabaseException must still propagate
        // cleanly — not crash with method-call-on-null.
        $pdo = $this->createStub(PDO::class);
        $this->scriptVersionQuery($pdo, '8.0.37');
        $pdo->method('exec')->willThrowException(new \PDOException('access denied'));
        $pdo->method('prepare')->willReturn($this->scriptedSelectStatement());

        $connection = new Connection($pdo, 'test');
        $connection->setQueryTimeoutMs(5000);

        $e = $this->assertThrows(DatabaseException::class, static fn () => $connection->query('SELECT 1'));
        $this->assertStringContainsString('access denied', $e->getMessage());
    }

    public function testQueryTimeoutResetsAppliedFlagWhenSetterCalledAgain(): void
    {
        // Setting a new timeout after the first SET SESSION must reset the
        // applied flag so the new value gets its own SET SESSION on the next
        // query — otherwise a re-configured value would be silently ignored.
        $pdo = $this->createMock(PDO::class);
        $this->scriptVersionQuery($pdo, '8.0.37');

        $matcher = $this->exactly(2);
        $pdo->expects($matcher)
            ->method('exec')
            ->willReturnCallback(function (string $sql) use ($matcher): int {
                $expected = match ($matcher->numberOfInvocations()) {
                    1 => 'SET SESSION max_execution_time = 5000',
                    2 => 'SET SESSION max_execution_time = 3000',
                    default => throw new \LogicException('unexpected exec call: ' . $sql),
                };
                $this->assertSame($expected, $sql);

                return 0;
            });
        $pdo->method('prepare')->willReturn($this->scriptedSelectStatement());

        $connection = new Connection($pdo, 'test');

        $connection->setQueryTimeoutMs(5000);
        $connection->query('SELECT 1');

        $connection->setQueryTimeoutMs(3000);
        $connection->query('SELECT 2');
    }

    /**
     * @param string|int|float|bool|null $value    Value handed to the connection
     * @param string                     $expected SQL text the value is written as
     */
    #[DataProvider('literalValues')]
    public function testQuoteLiteralWritesTheValueAsSqlText(
        string|int|float|bool|null $value,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->connection->quoteLiteral($value));
    }

    /**
     * @return array<string, array{0: string|int|float|bool|null, 1: string}>
     */
    public static function literalValues(): array
    {
        // PDOStatement::execute() binds an array of values as strings, so every
        // value but null is written as the string the server receives.
        return [
            'null'                     => [null, 'NULL'],
            'int'                      => [42, "'42'"],
            'negative int'             => [-7, "'-7'"],
            'float'                    => [1.5, "'1.5'"],
            'float without a fraction' => [2.0, "'2'"],
            'string'                   => ['alice', "'alice'"],
            'string with a quote'      => ["O'Brien", "'O''Brien'"],
            'true'                     => [true, "'1'"],
            'false'                    => [false, "''"],
            'infinity'                 => [\INF, "'INF'"],
            'not a number'             => [\NAN, "'NAN'"],
        ];
    }

    public function testQuoteLiteralOfABoolMatchesWhatTheStatementActuallyBinds(): void
    {
        // The rendering only earns its purpose if the value it shows is the one
        // the server compares against. PDO binds the array it is given as
        // strings, so a false has to read as the empty string here too.
        $this->pdo->exec('CREATE TABLE flags (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        $this->pdo->exec('INSERT INTO flags (id, label) VALUES (1, \'\'), (2, \'1\')');

        $matched = $this->connection->query('SELECT id FROM flags WHERE label = ?', [false])->asArray();

        $this->assertSame([['id' => 1]], $matched);
        $this->assertSame("''", $this->connection->quoteLiteral(false));
    }

    public function testQuoteLiteralFailsWhenTheDriverDeclinesToQuote(): void
    {
        // PDO::quote returns false on a driver that has no quoting of its own.
        // Returning the unquoted string would produce text that reads as SQL
        // rather than as a value, so the call fails instead.
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('does not support quoting a string');

        new Connection($pdo, 'test')->quoteLiteral('alice');
    }

    public function testQueryTimeoutDialectDetectionFailureLogsAndPropagates(): void
    {
        // Dialect detection runs `SELECT VERSION()` lazily; if that fails the
        // catch in applyQueryTimeoutIfPending must still surface the error
        // (with the same `failed to apply query timeout` log prefix as a
        // SET SESSION failure) rather than letting the connection silently
        // skip the timeout setup.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')
            ->willReturnCallback(function (string $sql): never {
                $this->assertSame('SELECT VERSION()', $sql);

                throw new \PDOException('server gone away');
            });
        $pdo->expects($this->never())->method('exec');

        $connection = new Connection($pdo, 'test');
        $handler    = $this->attachLogger($connection);
        $connection->setQueryTimeoutMs(5000);

        $e = $this->assertThrows(DatabaseException::class, static fn () => $connection->query('SELECT 1'));
        $this->assertStringContainsString('server gone away', $e->getMessage());

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Error, $records[0]->level);
        $this->assertStringStartsWith('failed to apply query timeout: ', $records[0]->message);
    }
}
