<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Dialect;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests pin what Expression::over() writes. What only a real server
// answers is which of those calls it accepts: the allowlist is MySQL's, and
// MariaDB refuses three of the shapes on it. The differences are pinned rather
// than avoided, because a call one server supports is not closed off for being
// unavailable on the other.
final class WindowExpressionTest extends TransactionalIntegrationTestCase
{
    use ThrowsAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->statement(
            'INSERT INTO users (id, name, email, status, score, created_at) VALUES'
                . ' (1, ?, ?, ?, ?, ?),'
                . ' (2, ?, ?, ?, ?, ?),'
                . ' (3, ?, ?, ?, ?, ?)',
            [
                'alice', 'alice@example.com', 'active', 10, '2020-01-01 10:00:00',
                'bob', 'bob@example.com', 'active', 25, '2021-06-15 12:30:00',
                'carol', 'carol@example.com', 'blocked', 40, '2022-12-31 23:59:59',
            ],
        );
    }

    public function testBothServersNumberRowsWithinEachPartition(): void
    {
        $rows = $this->connection
            ->select('id', [Expression::over(
                'ROW_NUMBER',
                partitions: ['status'],
                orders: ['score' => 'DESC'],
            ), 'rn'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame([['id' => 1, 'rn' => 2], ['id' => 2, 'rn' => 1], ['id' => 3, 'rn' => 1]], $rows);
    }

    public function testAQualifiedColumnInsideTheOverClauseNamesTheTableTheStatementReads(): void
    {
        // Without a prefix configured the two spellings coincide, so what this
        // pins is that a qualified name inside OVER (...) resolves at all --
        // the unit test holds the prefixed spelling, and this holds that the
        // server accepts what that rule produces.
        $rows = $this->connection
            ->select('id', [Expression::over(
                'ROW_NUMBER',
                partitions: ['users.status'],
                orders: ['users.score' => 'DESC'],
            ), 'rn'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame([['id' => 1, 'rn' => 2], ['id' => 2, 'rn' => 1], ['id' => 3, 'rn' => 1]], $rows);
    }

    public function testAnArgumentBoundInsideACallReachesTheServerAsAValue(): void
    {
        $rows = $this->connection
            ->select('id', [Expression::over('NTILE', [2], orders: ['score']), 'half'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame([1, 1, 2], array_column($rows, 'half'));
    }

    public function testBothServersTakeTwoArgumentsOfLagButOnlyMysqlTakesAThird(): void
    {
        $twoArguments = $this->connection
            ->select('id', [Expression::over('LAG', ['score', 1], orders: ['id']), 'previous'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame([null, 10, 25], array_column($twoArguments, 'previous'));

        // The third argument gives the value for the row that has no
        // predecessor. MariaDB 10.11 has no such parameter and refuses the call
        // outright, so the allowlist does not fix how many arguments a name
        // takes -- doing so would close off what MySQL supports.
        $withDefault = fn (): array => $this->connection
            ->select('id', [Expression::over('LAG', ['score', 1, 0], orders: ['id']), 'previous'])
            ->from('users')
            ->orderBy('id')
            ->get();

        if ($this->connection->dialect() === Dialect::MySQL) {
            $this->assertSame([0, 10, 25], array_column($withDefault(), 'previous'));

            return;
        }

        $this->assertSame(1064, $this->assertThrows(QueryException::class, $withDefault)->driverCode);
    }

    public function testOnlyMysqlAggregatesJsonOverAWindow(): void
    {
        $call = fn (): array => $this->connection
            ->select([Expression::over('JSON_ARRAYAGG', ['score'], partitions: ['status']), 'scores'])
            ->from('users')
            ->orderBy('id')
            ->get();

        if ($this->connection->dialect() === Dialect::MySQL) {
            $this->assertSame('[10, 25]', $call()[0]['scores']);

            return;
        }

        // 1235 is the server saying the feature is not implemented, not that
        // the statement is wrong -- the same code it gives for the JSON
        // aggregates over a window generally.
        $this->assertSame(1235, $this->assertThrows(QueryException::class, $call)->driverCode);
    }

    public function testMariadbRoundsTheSpreadItComputesOverAWindow(): void
    {
        $overWindow = $this->connection
            ->select([Expression::over('STDDEV_SAMP', ['score'], partitions: ['status']), 'spread'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $asAggregate = array_column(
            $this->connection
                ->query('SELECT STDDEV_SAMP(`score`) AS spread FROM `users` WHERE `status` = ?', ['active'])
                ->asArray(),
            'spread',
        )[0];

        // The same numbers, computed the same way, come back at a different
        // scale on MariaDB depending on whether a window was involved: as an
        // aggregate it answers in full, over a window it rounds to four
        // decimals. MySQL answers alike either way. A caller comparing the two
        // forms, or comparing against a stored figure, has to round first.
        if ($this->connection->dialect() === Dialect::MySQL) {
            $this->assertSame($asAggregate, $overWindow[0]['spread']);

            return;
        }

        $this->assertSame(10.6066, $overWindow[0]['spread']);
        $this->assertSame(10.606601717798213, $asAggregate);
    }

    public function testTheServerRefusesACallTheGrammarWritesButItDoesNotKnow(): void
    {
        // The allowlist is MySQL's list of window functions, so a grammar can
        // write a name this server has no window form for. The refusal is the
        // server's and it names the function, which is what keeps the list from
        // having to track every version of both engines.
        $call = fn (): array => $this->connection
            ->select(Expression::over('JSON_OBJECTAGG', ['name', 'score'], partitions: ['status']))
            ->from('users')
            ->get();

        if ($this->connection->dialect() === Dialect::MySQL) {
            $object = $call()[0]['JSON_OBJECTAGG(`name`, `score`) OVER (PARTITION BY `status`)'];

            self::assertIsString($object);
            $pairs = json_decode($object, true);
            self::assertIsArray($pairs);
            // The server decides what order it folds the rows in, so the pairs
            // are sorted before they are compared rather than the text.
            ksort($pairs);

            $this->assertSame(['alice' => 10, 'bob' => 25], $pairs);

            return;
        }

        $this->assertSame(1235, $this->assertThrows(QueryException::class, $call)->driverCode);
    }

    public function testTheFluentFormAnswersWhatTheFactoryFormDoes(): void
    {
        $viaFluent = $this->connection
            ->select('id', [Expression::rowNumber()->over()->partitionBy('status')->orderBy('score', 'DESC'), 'rn'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $viaFactory = $this->connection
            ->select('id', [Expression::over('ROW_NUMBER', partitions: ['status'], orders: ['score' => 'DESC']), 'rn'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame($viaFactory, $viaFluent);
        $this->assertSame([['id' => 1, 'rn' => 2], ['id' => 2, 'rn' => 1], ['id' => 3, 'rn' => 1]], $viaFluent);
    }

    public function testAFluentCallCarriesItsArgumentsToTheServer(): void
    {
        $rows = $this->connection
            ->select('id', [Expression::sum('score')->over()->partitionBy('status'), 'total'])
            ->from('users')
            ->orderBy('id')
            ->get();

        // Summing an integer column over a partition answers DECIMAL on both
        // servers, which PDO reads as a string; the COUNT in
        // testBothServersReadAnEmptyWindowAsEveryRow comes back as an integer. Pinned as returned rather than cast, so a change in
        // either direction shows up here. A window carrying bindings splits the
        // two servers instead -- see testMariadbRoundsTheSpreadItComputesOverAWindow
        // for the other asymmetry this family has.
        $this->assertSame(['35', '35', '40'], array_column($rows, 'total'));
    }

    public function testOnlyMysqlTakesADefaultWrittenThroughTheFluentForm(): void
    {
        // The fluent form writes the third argument only when it is given, so
        // this is the same split the factory form has -- reached the other way.
        $withDefault = fn (): array => $this->connection
            ->select([Expression::lag('score', 1, 0)->over()->orderBy('id'), 'previous'])
            ->from('users')
            ->orderBy('id')
            ->get();

        if ($this->connection->dialect() === Dialect::MySQL) {
            $this->assertSame([0, 10, 25], array_column($withDefault(), 'previous'));

            return;
        }

        $this->assertSame(1064, $this->assertThrows(QueryException::class, $withDefault)->driverCode);
    }

    public function testBothServersTakeAFluentCallLeftWithoutAnOffset(): void
    {
        $rows = $this->connection
            ->select([Expression::lag('score')->over()->orderBy('id'), 'previous'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame([null, 10, 25], array_column($rows, 'previous'));
    }

    public function testBothServersReadAnEmptyWindowAsEveryRow(): void
    {
        // over() with nothing after it writes OVER (), which the unit tests
        // pin as a spelling. What this holds is that both servers read it as
        // the whole result rather than refusing it.
        $rows = $this->connection
            ->select('id', [Expression::count()->over(), 'rows_total'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame([3, 3, 3], array_column($rows, 'rows_total'));
    }

    public function testBothServersSortAWindowBySeveralTermsInTheOrderTheyWereAdded(): void
    {
        $rows = $this->connection
            ->select('id', [Expression::rowNumber()->over()->orderBy('status')->orderBy('score', 'DESC'), 'rn'])
            ->from('users')
            ->orderBy('id')
            ->get();

        $this->assertSame([['id' => 1, 'rn' => 2], ['id' => 2, 'rn' => 1], ['id' => 3, 'rn' => 3]], $rows);
    }
}
