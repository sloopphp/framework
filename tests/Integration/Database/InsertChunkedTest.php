<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use ArrayObject;
use Generator;
use Sloop\Database\Connection;
use Sloop\Database\Exception\UniqueConstraintViolationException;
use Sloop\Tests\Support\IntegrationTestCase;
use Sloop\Tests\Support\ThrowsAssertions;

/*
 * What a chunked insert leaves behind on a real server.
 *
 * Not transactional: the per-test transaction of the shared base class would
 * be the outer transaction insertChunked() joins, which is the one thing these
 * tests are here to tell apart. Each test drops and recreates its own table.
 */
final class InsertChunkedTest extends IntegrationTestCase
{
    use ThrowsAssertions;

    private const string TABLE = 'sloop_chunked_rows';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::openConnection();
        $this->connection->statement('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->connection->statement(
            'CREATE TABLE ' . self::TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'name VARCHAR(64) NOT NULL, '
                . 'score INT NOT NULL, '
                . 'UNIQUE KEY chunked_name_unique (name)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    protected function tearDown(): void
    {
        $this->connection->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    private function countOn(Connection $connection): int
    {
        foreach ($connection->query('SELECT COUNT(*) AS n FROM ' . self::TABLE) as $row) {
            self::assertTrue(\is_int($row['n']) || \is_string($row['n']));

            return (int) $row['n'];
        }

        self::fail('COUNT(*) returned no row.');
    }

    private function rowCount(): int
    {
        return $this->countOn($this->connection);
    }

    private function firstName(): string
    {
        foreach ($this->connection->query('SELECT name FROM ' . self::TABLE . ' ORDER BY id LIMIT 1') as $row) {
            self::assertIsString($row['name']);

            return $row['name'];
        }

        self::fail('The table holds no row.');
    }

    /**
     * @param  int                                             $count How many rows to yield
     * @return Generator<int, array{name: string, score: int}>
     */
    private function numberedRows(int $count): Generator
    {
        for ($i = 1; $i <= $count; $i++) {
            yield ['name' => \sprintf('row-%04d', $i), 'score' => $i];
        }
    }

    /**
     * Rows that run into the unique key once $before of them have gone in.
     *
     * @param  int                                             $before How many rows to yield before the colliding one
     * @return Generator<int, array{name: string, score: int}>
     */
    private function rowsCollidingAfter(int $before): Generator
    {
        yield from $this->numberedRows($before);

        yield ['name' => 'row-0001', 'score' => 0];
    }

    public function testWritesEveryRowOfEveryChunk(): void
    {
        $written = $this->connection->insertChunked(self::TABLE, $this->numberedRows(250), chunkSize: 100);

        $this->assertSame(250, $written);
        $this->assertSame(250, $this->rowCount());
        $this->assertSame('row-0001', $this->firstName());
    }

    public function testTheChunkSizeSaysHowManyStatementsAreSentAndNotWhatGoesIn(): void
    {
        // The rows and the count come out the same whatever the chunk size;
        // what changes is how many statements the server sees. The progress
        // calls are what makes that visible from here.
        /** @var ArrayObject<int, array{int, int}> $chunks */
        $chunks = new ArrayObject();

        $written = $this->connection->insertChunked(
            self::TABLE,
            $this->numberedRows(10),
            chunkSize: 3,
            onProgress: static function (int $inserted, int $chunkIndex) use ($chunks): void {
                $chunks->append([$inserted, $chunkIndex]);
            },
        );

        $this->assertSame(10, $written);
        $this->assertSame([[3, 0], [6, 1], [9, 2], [10, 3]], $chunks->getArrayCopy());
        $this->assertSame(10, $this->rowCount());
    }

    public function testAnAtomicBatchTakesTheEarlierChunksBackWhenALaterOneFails(): void
    {
        $this->assertThrows(
            UniqueConstraintViolationException::class,
            fn (): int => $this->connection->insertChunked(
                self::TABLE,
                $this->rowsCollidingAfter(4),
                chunkSize: 2,
            ),
        );

        $this->assertSame(0, $this->rowCount());
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testWithoutAnAtomicBatchTheChunksBeforeTheFailureAreCommitted(): void
    {
        $this->assertThrows(
            UniqueConstraintViolationException::class,
            fn (): int => $this->connection->insertChunked(
                self::TABLE,
                $this->rowsCollidingAfter(4),
                chunkSize: 2,
                atomicBatch: false,
            ),
        );

        $this->assertSame(4, $this->rowCount());
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testAnOpenTransactionOwnsTheRowsWhicheverBatchModeIsAsked(): void
    {
        // The chunks join the caller's transaction rather than committing on
        // their own, so atomicBatch = false does not commit per chunk here.
        foreach ([true, false] as $atomicBatch) {
            $this->connection->begin();
            $this->connection->insertChunked(
                self::TABLE,
                $this->numberedRows(4),
                chunkSize: 2,
                atomicBatch: $atomicBatch,
            );
            $this->connection->rollback();

            $this->assertSame(0, $this->rowCount(), 'atomicBatch: ' . var_export($atomicBatch, true));
        }
    }

    public function testTheRowsOfAnAtomicBatchAreNotVisibleUntilItIsDone(): void
    {
        // A second connection reads the table while the batch is running. Under
        // the default isolation level of both servers an uncommitted row is not
        // there for it, which is what "one transaction" buys over per-chunk
        // commits and cannot be seen from inside the writing connection.
        $observer = self::openConnection();

        /** @var ArrayObject<int, int> $seen */
        $seen = new ArrayObject();

        $this->connection->insertChunked(
            self::TABLE,
            $this->numberedRows(4),
            chunkSize: 2,
            onProgress: function () use ($observer, $seen): void {
                $seen->append($this->countOn($observer));
            },
        );

        $this->assertSame([0, 0], $seen->getArrayCopy());
        $this->assertSame(4, $this->rowCount());
    }

    public function testWithoutAnAtomicBatchEachChunkIsVisibleAsItLands(): void
    {
        $observer = self::openConnection();

        /** @var ArrayObject<int, int> $seen */
        $seen = new ArrayObject();

        $this->connection->insertChunked(
            self::TABLE,
            $this->numberedRows(4),
            chunkSize: 2,
            atomicBatch: false,
            onProgress: function () use ($observer, $seen): void {
                $seen->append($this->countOn($observer));
            },
        );

        $this->assertSame([2, 4], $seen->getArrayCopy());
    }

}
