<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DeadlockException;
use Sloop\Database\IsolationLevel;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Database\Stub\RecordingSqlite;

/*
 * Pins what each call sends to the driver.
 *
 * The other tests cover what a call returns and how often a retried closure
 * runs. Neither notices a change that keeps the return value while sending
 * more than before: a second execute added inside query(), a memoised lookup
 * that stops memoising, a retry that reopens a transaction it did not need to.
 * An assertion on the result cannot see any of them, and a counter on the
 * closure sees only the closure.
 *
 * Recording happens at the driver rather than through Connection's query log,
 * because that log is written once per call after the work is done and skips
 * begin(), commit(), rollback(), ping() and the version lookup entirely.
 *
 * Not covered here: SET TRANSACTION ISOLATION LEVEL, the query timeout
 * statement and ping() are MySQL and MariaDB syntax that SQLite rejects, so
 * they cannot run in this suite. They belong to the integration tests.
 */
final class ConnectionQueryEmissionTest extends TestCase
{
    use ThrowsAssertions;

    private RecordingSqlite $pdo;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->pdo = new RecordingSqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->pdo->createFunction('version', static fn (): string => '10.11.11-MariaDB');

        // Drop the setup calls so each test starts from an empty record.
        $this->pdo->calls = [];

        $this->connection = new Connection($this->pdo, 'emission_test');
    }

    public function testQuerySendsOneStatement(): void
    {
        $this->connection->query('SELECT 1');

        $this->assertSame(['prepare: SELECT 1'], $this->pdo->calls);
    }

    public function testStatementSendsOneStatement(): void
    {
        $this->connection->statement('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'alice']);

        $this->assertSame(['prepare: INSERT INTO users (id, name) VALUES (?, ?)'], $this->pdo->calls);
    }

    public function testTransactionWrapsTheClosureInOneBeginAndCommit(): void
    {
        $this->connection->transaction(function (): void {
            $this->connection->statement('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'alice']);
        });

        $this->assertSame(
            [
                'beginTransaction',
                'prepare: INSERT INTO users (id, name) VALUES (?, ?)',
                'commit',
            ],
            $this->pdo->calls,
        );
    }

    public function testFailedTransactionRollsBackWithoutCommitting(): void
    {
        // The rollback is the subject; the exception itself is covered elsewhere.
        $this->assertThrows(
            RuntimeException::class,
            fn () => $this->connection->transaction(function (): void {
                $this->connection->statement('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'alice']);

                throw new RuntimeException('boom');
            }),
        );

        $this->assertSame(
            [
                'beginTransaction',
                'prepare: INSERT INTO users (id, name) VALUES (?, ?)',
                'rollBack',
            ],
            $this->pdo->calls,
        );
    }

    public function testEachRetryOpensAndClosesItsOwnTransaction(): void
    {
        $attempts = new class () {
            public int $value = 0;
        };

        $this->connection->transaction(
            function () use ($attempts): string {
                $attempts->value++;
                $this->connection->statement(
                    'INSERT INTO users (id, name) VALUES (?, ?)',
                    [$attempts->value, 'alice'],
                );

                if ($attempts->value < 3) {
                    throw new DeadlockException('deadlock', '', [], 'emission_test', '40001', 1213);
                }

                return 'ok';
            },
            IsolationLevel::Default,
            5,
        );

        // Two failed attempts roll back and a third commits. A retry policy
        // that reused a transaction, or replayed the statement without
        // reopening one, would change this sequence.
        $this->assertSame(
            [
                'beginTransaction',
                'prepare: INSERT INTO users (id, name) VALUES (?, ?)',
                'rollBack',
                'beginTransaction',
                'prepare: INSERT INTO users (id, name) VALUES (?, ?)',
                'rollBack',
                'beginTransaction',
                'prepare: INSERT INTO users (id, name) VALUES (?, ?)',
                'commit',
            ],
            $this->pdo->calls,
        );
    }

    public function testVersionIsLookedUpOnceAcrossCalls(): void
    {
        $this->connection->dialect();
        $this->connection->dialect();

        // The version is memoised twice over: dialect() keeps the Dialect and
        // serverVersion() keeps the string it was built from. Dropping either
        // one on its own still costs a single round trip, which is why
        // infection.json5 treats those two mutations as equivalent. What this
        // pins is the pair: lose both and every read reaches the server.
        $this->assertSame(['query: SELECT VERSION()'], $this->pdo->calls);
    }
}
