<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use ArrayObject;
use Generator;
use InvalidArgumentException;
use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\Connection;
use Sloop\Tests\Support\ThrowsAssertions;

final class ConnectionInsertChunkedTest extends TestCase
{
    use ThrowsAssertions;

    private Connection $connection;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, score INTEGER)');

        $this->connection = new Connection($sqlite, 'chunked_test');
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $names = [];

        foreach ($this->connection->query('SELECT name FROM users ORDER BY id') as $row) {
            self::assertIsString($row['name']);

            $names[] = $row['name'];
        }

        return $names;
    }

    /**
     * @param  string                                $names Names to yield one row each
     * @return Generator<int, array<string, string>>
     */
    private function generateNames(string ...$names): Generator
    {
        foreach ($names as $name) {
            yield ['name' => $name];
        }
    }

    /**
     * @param  int                                   $count How many rows to yield
     * @return Generator<int, array<string, string>>
     */
    private function generateNumbered(int $count): Generator
    {
        for ($i = 1; $i <= $count; $i++) {
            yield ['name' => 'row-' . $i];
        }
    }

    /**
     * Six rows with one of them replaced, to place a bad row where it is asked for.
     *
     * @param  int                   $at  Position of the replaced element among the six
     * @param  mixed                 $bad What to yield there
     * @return Generator<int, mixed>
     */
    private function generateWithOneBadRow(int $at, mixed $bad): Generator
    {
        for ($i = 0; $i < 6; $i++) {
            yield $i === $at ? $bad : ['name' => 'row-' . $i];
        }
    }

    public function testWritesEveryRowAndReportsHowManyWentIn(): void
    {
        $written = $this->connection->insertChunked('users', [
            ['name' => 'alice', 'score' => 1],
            ['name' => 'bob', 'score' => 2],
            ['name' => 'carol', 'score' => 3],
        ]);

        $this->assertSame(3, $written);
        $this->assertSame(['alice', 'bob', 'carol'], $this->names());
    }

    public function testReadsRowsFromAGenerator(): void
    {
        $written = $this->connection->insertChunked('users', $this->generateNames('alice', 'bob'));

        $this->assertSame(2, $written);
        $this->assertSame(['alice', 'bob'], $this->names());
    }

    public function testSendsOneStatementPerChunk(): void
    {
        /** @var ArrayObject<int, array{int, int}> $chunks */
        $chunks = new ArrayObject();

        $written = $this->connection->insertChunked(
            'users',
            $this->generateNames('a', 'b', 'c', 'd', 'e'),
            chunkSize: 2,
            onProgress: static function (int $inserted, int $chunkIndex) use ($chunks): void {
                $chunks->append([$inserted, $chunkIndex]);
            },
        );

        $this->assertSame(5, $written);
        $this->assertSame([[2, 0], [4, 1], [5, 2]], $chunks->getArrayCopy());
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $this->names());
    }

    public function testProgressIsNotCalledForRowsThatFillNoChunk(): void
    {
        /** @var ArrayObject<int, true> $calls */
        $calls = new ArrayObject();

        $this->connection->insertChunked(
            'users',
            [],
            onProgress: static function () use ($calls): void {
                $calls->append(true);
            },
        );

        $this->assertSame([], $calls->getArrayCopy());
    }

    public function testAnEmptyIterableWritesNothingAndReportsZero(): void
    {
        $this->assertSame(0, $this->connection->insertChunked('users', []));
        $this->assertSame([], $this->names());
    }

    public function testTheChunkSizeDefaultsToAThousandRows(): void
    {
        // 1001 rows split at the default into one full chunk and one row. The
        // progress calls say where the boundary fell, which is the only place
        // the default is visible: the rows and the count come out the same
        // whatever it is.
        /** @var ArrayObject<int, array{int, int}> $chunks */
        $chunks = new ArrayObject();

        $written = $this->connection->insertChunked(
            'users',
            $this->generateNumbered(1001),
            onProgress: static function (int $inserted, int $chunkIndex) use ($chunks): void {
                $chunks->append([$inserted, $chunkIndex]);
            },
        );

        $this->assertSame(1001, $written);
        $this->assertSame([[1000, 0], [1001, 1]], $chunks->getArrayCopy());
    }

    public function testABatchIsAtomicUnlessSaidOtherwise(): void
    {
        // Whether the default opens a transaction cannot be read back from the
        // rows, since either way they are all there once the call returns.
        /** @var ArrayObject<int, bool> $open */
        $open = new ArrayObject();

        $this->connection->insertChunked(
            'users',
            $this->generateNames('alice', 'bob'),
            chunkSize: 1,
            onProgress: function () use ($open): void {
                $open->append($this->connection->inTransaction());
            },
        );

        $this->assertSame([true, true], $open->getArrayCopy());
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testRefusesAChunkSizeBelowOne(): void
    {
        $thrown = $this->assertThrows(
            LogicException::class,
            fn (): int => $this->connection->insertChunked('users', [['name' => 'alice']], chunkSize: 0),
        );

        $this->assertSame('chunkSize must be at least 1, got 0.', $thrown->getMessage());
    }

    public function testRefusesARowNamingDifferentColumnsThanTheFirstOfAnotherChunk(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): int => $this->connection->insertChunked(
                'users',
                [
                    ['name' => 'alice', 'score' => 1],
                    ['name' => 'bob'],
                ],
                chunkSize: 1,
            ),
        );

        $this->assertSame(
            'Every row of insertChunked() writes the same columns, since a chunk is one INSERT and the chunks are'
                . ' split by count rather than by what the rows name. The first row names "name", "score" and the'
                . ' row at index 1 names "name".',
            $thrown->getMessage(),
        );
    }

    public function testNamesTheRowThatCameWithNoColumnAtAll(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): int => $this->connection->insertChunked(
                'users',
                [['name' => 'alice'], []],
                chunkSize: 1,
            ),
        );

        $this->assertSame(
            'Every row of insertChunked() writes the same columns, since a chunk is one INSERT and the chunks are'
                . ' split by count rather than by what the rows name. The first row names "name" and the row at'
                . ' index 1 names no column.',
            $thrown->getMessage(),
        );
    }

    public function testAnAtomicBatchLeavesNothingBehindWhenALaterChunkFails(): void
    {
        $this->assertThrows(
            RuntimeException::class,
            fn (): int => $this->connection->insertChunked(
                'users',
                $this->generateNames('alice', 'bob', 'alice'),
                chunkSize: 1,
            ),
        );

        $this->assertSame([], $this->names());
    }

    public function testWithoutAnAtomicBatchTheChunksBeforeTheFailureStay(): void
    {
        $this->assertThrows(
            RuntimeException::class,
            fn (): int => $this->connection->insertChunked(
                'users',
                $this->generateNames('alice', 'bob', 'alice'),
                chunkSize: 1,
                atomicBatch: false,
            ),
        );

        $this->assertSame(['alice', 'bob'], $this->names());
    }

    public function testAnOpenTransactionCarriesTheRowsRatherThanANestedOne(): void
    {
        $this->connection->begin();
        $written = $this->connection->insertChunked('users', [['name' => 'alice']]);
        $this->connection->rollback();

        $this->assertSame(1, $written);
        $this->assertSame([], $this->names());
    }

    public function testAnOpenTransactionAlsoCarriesThemWithoutAnAtomicBatch(): void
    {
        $this->connection->begin();
        $this->connection->insertChunked('users', [['name' => 'alice']], atomicBatch: false);
        $this->connection->rollback();

        $this->assertSame([], $this->names());
    }

    public function testRefusesAnElementThatIsNotARow(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): int => $this->connection->insertChunked('users', ['alice']),
        );

        $this->assertSame(
            'insertChunked() writes a row per element, so each element must be an array, got string at index 0.',
            $thrown->getMessage(),
        );
    }

    public function testNamesABadRowByItsPlaceAmongThemAllAndNotWithinItsChunk(): void
    {
        // Which chunk a row lands in is a matter of $chunkSize, so a message
        // counting from the start of a chunk would leave the caller of a large
        // import unable to find the row. Both kinds of bad row are checked
        // here, at a position that is not the head of its chunk.
        $mismatch = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): int => $this->connection->insertChunked(
                'users',
                $this->generateWithOneBadRow(4, ['name' => 'odd', 'score' => 1]),
                chunkSize: 3,
            ),
        );

        $this->assertSame(
            'Every row of insertChunked() writes the same columns, since a chunk is one INSERT and the chunks are'
                . ' split by count rather than by what the rows name. The first row names "name" and the row at'
                . ' index 4 names "name", "score".',
            $mismatch->getMessage(),
        );

        $notARow = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): int => $this->connection->insertChunked(
                'users',
                $this->generateWithOneBadRow(4, 'oops'),
                chunkSize: 3,
            ),
        );

        $this->assertSame(
            'insertChunked() writes a row per element, so each element must be an array, got string at index 4.',
            $notARow->getMessage(),
        );
    }

}
