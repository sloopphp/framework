<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Sloop\Database\Connection;
use Sloop\Database\Dialect;
use Sloop\Database\Exception\LockNotAvailableException;
use Sloop\Database\Exception\LockWaitTimeoutException;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\IntegrationTestCase;

/**
 * What the locking clauses do to a second session reading the same rows.
 *
 * Two sessions are needed to see a lock at all, and the rows have to be
 * committed for the second one to reach them, so these tests open their own
 * connections rather than running inside TransactionalIntegrationTestCase's
 * per-test transaction.
 */
final class SelectLockTest extends IntegrationTestCase
{
    private const string TABLE = 'sloop_lock_rows';

    private Connection $holder;

    private Connection $reader;

    private int $attempts = 0;

    public static function setUpBeforeClass(): void
    {
        $connection = static::openConnection();
        $connection->statement('DROP TABLE IF EXISTS ' . self::TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL PRIMARY KEY, '
                . 'label VARCHAR(50) NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->statement(
            'INSERT INTO ' . self::TABLE . ' (id, label) VALUES (1, ?), (2, ?), (3, ?)',
            ['one', 'two', 'three'],
        );
    }

    protected function setUp(): void
    {
        $this->holder = static::openConnection();
        $this->reader = static::openConnection();

        // Without a short wait the blocking cases would sit on the server's
        // default of fifty seconds before reporting what the test is asking.
        $this->reader->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        foreach ([$this->reader, $this->holder] as $connection) {
            if ($connection->inTransaction()) {
                $connection->rollback();
            }
        }
    }

    /**
     * Take an exclusive lock on one row and leave the transaction open.
     */
    private function holdRow(int $id): void
    {
        $this->holder->begin();
        $this->holder->select('id')->from(self::TABLE)->where('id', $id)->forUpdate()->get();
    }

    public function testForUpdateHoldsTheRowAgainstAnotherSession(): void
    {
        $this->holdRow(1);
        $this->reader->begin();

        $this->expectException(LockWaitTimeoutException::class);

        $this->reader->select('id')->from(self::TABLE)->where('id', 1)->forUpdate()->get();
    }

    public function testForUpdateLeavesRowsItDidNotReadAlone(): void
    {
        // A negative control: it reads the rows the holder did not take, so it
        // passes with no lock written at all. NOWAIT is here to keep it from
        // waiting out the timeout if the holder ever over-locks. That the lock
        // is written at all is held by the other cases in this class.
        $this->holdRow(1);
        $this->reader->begin();

        $this->assertSame(
            [2],
            $this->reader->select('id')->from(self::TABLE)->where('id', 2)->forUpdate(noWait: true)->pluck('id'),
        );
    }

    public function testSkipLockedLeavesOutTheRowsAnotherSessionHolds(): void
    {
        $this->holdRow(1);
        $this->reader->begin();

        $this->assertSame(
            [2, 3],
            $this->reader->select('id')->from(self::TABLE)->orderBy('id')->forUpdate(skipLocked: true)->pluck('id'),
        );
    }

    public function testNoWaitFailsInsteadOfWaitingForARowAnotherSessionHolds(): void
    {
        $this->holdRow(1);
        $this->reader->begin();

        // Which exception this arrives as is the server's to decide. MySQL has
        // a code of its own for a NOWAIT that could not take the lock; MariaDB
        // reuses the one it returns for an ordinary lock wait, so the failure
        // reads as a timeout even though nothing waited. What that costs is
        // pinned by testNoWaitIsRetriedOnTheServerThatCannotTellItApartFromAWait.
        $expected = $this->reader->dialect() === Dialect::MySQL
            ? LockNotAvailableException::class
            : LockWaitTimeoutException::class;

        $this->expectException($expected);

        $this->reader->select('id')->from(self::TABLE)->where('id', 1)->forUpdate(noWait: true)->get();
    }

    public function testASharedLockLetsAnotherSessionTakeTheSameLock(): void
    {
        $this->holder->begin();
        $this->holder->select('id')->from(self::TABLE)->where('id', 1)->sharedLock()->get();

        $this->reader->begin();

        $this->assertSame(
            [1],
            $this->reader->select('id')->from(self::TABLE)->where('id', 1)->sharedLock()->pluck('id'),
        );

        // Reading the row proves it was not blocked, not that a lock was taken:
        // the same rows come back with no lock at all. Letting the first session
        // go and then writing from a third one asks what the reader is holding.
        $this->holder->rollback();

        $writer = static::openConnection();
        $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $writer->begin();

        try {
            $this->expectException(LockWaitTimeoutException::class);

            $writer->statement('UPDATE ' . self::TABLE . ' SET label = ? WHERE id = 1', ['written']);
        } finally {
            $writer->rollback();
        }
    }

    public function testASharedLockHoldsTheRowAgainstAWrite(): void
    {
        $this->holder->begin();
        $this->holder->select('id')->from(self::TABLE)->where('id', 1)->sharedLock()->get();

        $this->reader->begin();

        $this->expectException(LockWaitTimeoutException::class);

        $this->reader->statement('UPDATE ' . self::TABLE . ' SET label = ? WHERE id = 1', ['written']);
    }

    public function testAnExclusiveLockRefusesASharedLockOnTheSameRow(): void
    {
        $this->holdRow(1);
        $this->reader->begin();

        $this->expectException(LockWaitTimeoutException::class);

        $this->reader->select('id')->from(self::TABLE)->where('id', 1)->sharedLock()->get();
    }

    #[DataProvider('provideHeldRows')]
    public function testTheServerStillMiscountsUnderANoWaitLock(int $held, int $counted): void
    {
        // The premise count() refuses on. Written against the server rather
        // than the builder, so that the day MySQL starts reporting this
        // failure, the refusal is known to have become unnecessary instead of
        // outliving its reason. SelectTest pins the refusal itself, which is
        // decided before a connection is asked for.
        //
        // Both ends of the scan are here on purpose. Holding the first row
        // answers 0, which a caller might think to distrust; holding the last
        // answers one short of the truth, which is the reading nothing marks as
        // wrong and the reason the refusal has to be unconditional. Measuring
        // only the first row is what produced a wrong description of this
        // behaviour once already.
        if ($this->reader->dialect() !== Dialect::MySQL) {
            $this->markTestSkipped('MariaDB reports this failure rather than answering with a number.');
        }

        $this->holdRow($held);
        $this->reader->begin();

        $row = $this->reader->query(
            'SELECT COUNT(*) AS c FROM ' . self::TABLE . ' FOR UPDATE NOWAIT',
        )->first();

        $this->assertSame($counted, $row['c'] ?? null);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function provideHeldRows(): array
    {
        // Three rows match in every case.
        return [
            'the first row of the scan' => [1, 0],
            'the middle row'            => [2, 1],
            'the last row'              => [3, 2],
        ];
    }

    public function testCountRefusesANoWaitLock(): void
    {
        // Refused before a connection is asked for, so this reaches no server;
        // it is here beside the case above to keep the refusal and its premise
        // in one place.
        $this->expectException(LogicException::class);

        $this->reader->select()->from(self::TABLE)->forUpdate(noWait: true)->count();
    }

    public function testCountUnderAPlainLockReportsTheWaitInsteadOfANumber(): void
    {
        // The other locks are left alone because the server answers them
        // properly: a plain FOR UPDATE waits for the held row and then reports
        // the wait.
        $this->holdRow(1);
        $this->reader->begin();

        $this->expectException(LockWaitTimeoutException::class);

        $this->reader->select()->from(self::TABLE)->forUpdate()->count();
    }

    public function testCountUnderSkipLockedCountsWhatItCouldTake(): void
    {
        $this->holdRow(1);
        $this->reader->begin();

        $this->assertSame(
            2,
            $this->reader->select()->from(self::TABLE)->forUpdate(skipLocked: true)->count(),
        );
    }

    public function testNoWaitIsRetriedOnTheServerThatCannotTellItApartFromAWait(): void
    {
        // Asking not to wait and then being run again is the opposite of what
        // NOWAIT is for, and it happens because MariaDB reports the failure
        // with the code it uses for an ordinary lock wait, which
        // Connection::shouldRetry() treats as worth retrying. Pinned here so
        // that a change to that list, or to the code MariaDB returns, is not
        // silent.
        $this->holdRow(1);

        try {
            $this->reader->transaction(function (Connection $connection): void {
                $this->attempts++;

                $connection->select('id')->from(self::TABLE)->where('id', 1)->forUpdate(noWait: true)->get();
            }, maxAttempts: 3);

            $this->fail('The held row should have refused the lock.');
        } catch (LockNotAvailableException) {
            $this->assertSame(1, $this->attempts, 'MySQL names this failure, so it is not retried.');
        } catch (LockWaitTimeoutException) {
            $this->assertSame(3, $this->attempts, 'MariaDB reuses the wait code, so the callback runs again.');
        }
    }

    public function testASkipLockedExistsReadsAHeldRowAsAbsent(): void
    {
        // exists() answers whether a row is there for the taking, not whether
        // one is there. Pinned against the server for the same reason the
        // miscount is: the shortcut reads false while the row is plainly
        // present, so the day that changes it should not pass unnoticed.
        $this->holdRow(1);
        $this->reader->begin();

        $query = fn (): Select => $this->reader->select('id')->from(self::TABLE)->where('id', 1);

        $this->assertFalse($query()->forUpdate(skipLocked: true)->exists());
        $this->assertTrue($query()->exists());
    }
}
