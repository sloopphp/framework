<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Connection;
use Sloop\Database\Exception\QueryException;
use Sloop\Tests\Support\IntegrationTestCase;

// What these tests watch is the clock. A limit that took effect shows up as a
// statement that stopped early, and that is the same on both servers; whether
// the server then says why it stopped is not — measured over twenty runs each,
// one of them reports the limit on the statement used here and the other
// returns a row as though nothing happened. The database guide carries the
// numbers. So the calls below accept either answer and assert the time.
final class SelectTimeoutTest extends IntegrationTestCase
{
    private const string TABLE = 'sloop_timeout_rows';

    /** Milliseconds the slow statement is allowed, far under what it needs. */
    private const int LIMIT_MS = 400;

    /** Milliseconds a limit is raised to when the point is that it does not fire. */
    private const int GENEROUS_MS = 30000;

    /**
     * Hashing rounds the slow statement asks for.
     *
     * Sized from the unlimited statement taking upwards of three seconds on
     * both servers, which testTheStatementIsSlowWithoutTheLimit holds to so
     * that the rest of these tests cannot pass on a statement that got fast.
     */
    private const int ROUNDS = 5000000;

    private Connection $connection;

    public static function setUpBeforeClass(): void
    {
        $connection = static::openConnection();
        $connection->statement('DROP TABLE IF EXISTS ' . self::TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL PRIMARY KEY, '
                . 'n INT NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->statement('INSERT INTO ' . self::TABLE . ' (id, n) VALUES (1, 1), (2, 2), (3, 3)');
    }

    public static function tearDownAfterClass(): void
    {
        static::openConnection()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function setUp(): void
    {
        $this->connection = static::openConnection();
    }

    /**
     * Run the slow statement under the given limit and return how long it took.
     *
     * The work is a hash repeated until it outlasts any limit these tests set.
     * It sits in a condition of the statement itself rather than in a subquery,
     * which keeps what the servers do with it down to the two answers described
     * at the top of this class; a subquery adds a third, where the same server
     * reports the limit on some runs and not others.
     *
     * @param  int|null $timeoutMs Milliseconds to allow, or null to set no limit
     * @return float    Elapsed milliseconds
     */
    private function runSlowStatement(?int $timeoutMs): float
    {
        $select = $this->connection
            ->select('id')
            ->from(self::TABLE)
            ->whereRaw('BENCHMARK(' . self::ROUNDS . ', SHA2(RAND(), 512)) = 0')
            ->limit(1);

        if ($timeoutMs !== null) {
            $select->timeout($timeoutMs);
        }

        $started = microtime(true);

        try {
            $select->execute();
        } catch (QueryException) {
            // Either answer means the server stopped; the clock says whether
            // it stopped when it was told to.
        }

        return (microtime(true) - $started) * 1000;
    }

    private function assertStoppedEarly(float $elapsedMs): void
    {
        // Loose on both sides: the server checks the clock between units of
        // work rather than at the millisecond, and a busy machine should not
        // turn this red.
        $this->assertGreaterThan(self::LIMIT_MS * 0.5, $elapsedMs);
        $this->assertLessThan(self::LIMIT_MS * 5, $elapsedMs);
    }

    public function testTheStatementIsSlowWithoutTheLimit(): void
    {
        // Without this the rest of the class would keep passing if the work
        // ever stopped being slow, since a fast statement also finishes early.
        $this->assertGreaterThan(self::LIMIT_MS * 5, $this->runSlowStatement(self::GENEROUS_MS));
    }

    public function testTimeoutStopsAStatementTheServerIsStillWorkingOn(): void
    {
        $this->assertStoppedEarly($this->runSlowStatement(self::LIMIT_MS));
    }

    public function testTimeoutStillStopsAStatementThatTakesALock(): void
    {
        // Measured rather than assumed: a lock clause changes what the server
        // is allowed to do with the statement, and a limit going quiet here
        // would be the hardest kind to notice.
        $this->connection->begin();

        try {
            $started = microtime(true);

            try {
                $this->connection
                    ->select('id')
                    ->from(self::TABLE)
                    ->whereRaw('BENCHMARK(' . self::ROUNDS . ', SHA2(RAND(), 512)) = 0')
                    ->limit(1)
                    ->forUpdate()
                    ->timeout(self::LIMIT_MS)
                    ->execute();
            } catch (QueryException) {
                // As above.
            }

            $elapsed = (microtime(true) - $started) * 1000;
        } finally {
            $this->connection->rollback();
        }

        $this->assertStoppedEarly($elapsed);
    }

    public function testTimeoutLeavesAStatementThatFinishesInTimeAlone(): void
    {
        $rows = $this->connection
            ->select('id', 'n')
            ->from(self::TABLE)
            ->orderBy('id')
            ->timeout(self::GENEROUS_MS)
            ->get();

        $this->assertSame(
            [['id' => 1, 'n' => 1], ['id' => 2, 'n' => 2], ['id' => 3, 'n' => 3]],
            $rows,
        );
    }

    public function testTimeoutRidesAlongWithBoundValues(): void
    {
        // Everything else here compiles to a statement with no placeholders,
        // so nothing would notice if the rewriting stopped surviving the
        // prepare.
        $rows = $this->connection
            ->select('id')
            ->from(self::TABLE)
            ->where('n', 2)
            ->timeout(self::GENEROUS_MS)
            ->get();

        $this->assertSame([['id' => 2]], $rows);
    }

    public function testTimeoutOnTheStatementOverridesAMoreGenerousSessionOne(): void
    {
        $this->connection->setQueryTimeoutMs(self::GENEROUS_MS);

        $this->assertStoppedEarly($this->runSlowStatement(self::LIMIT_MS));
    }

    public function testTimeoutOnTheStatementOverridesAShorterSessionOne(): void
    {
        // The other direction: a statement allowed more than the session gets
        // it, rather than being cut down to what the session says.
        $this->connection->setQueryTimeoutMs(self::LIMIT_MS);

        $this->assertGreaterThan(self::LIMIT_MS * 5, $this->runSlowStatement(self::GENEROUS_MS));
    }

    public function testAStatementWithoutATimeoutStillTakesTheSessionOne(): void
    {
        // The per-statement form overrides rather than replaces: leaving it
        // unset has to leave the configured limit doing its job.
        $this->connection->setQueryTimeoutMs(self::LIMIT_MS);

        $this->assertStoppedEarly($this->runSlowStatement(null));
    }
}
