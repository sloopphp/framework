<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Dialect;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests run against SQLite, which has window functions of its own.
// What only a real server can answer is where it lets one appear and what type
// a value computed by one comes back as, since both differ between the two
// engines the suite runs against.
final class SelectWindowTest extends TransactionalIntegrationTestCase
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
            ->select('id', Expression::of('ROW_NUMBER() OVER (PARTITION BY `status` ORDER BY `score` DESC) AS rn'))
            ->from('users')
            ->orderBy('id')
            ->get();

        // bob outscores alice, so alice is second within 'active'; carol is
        // alone in 'blocked' and the count starts over at her.
        $this->assertSame([['id' => 1, 'rn' => 2], ['id' => 2, 'rn' => 1], ['id' => 3, 'rn' => 1]], $rows);
    }

    public function testAValueComputedByAWindowFunctionCarriesTheServersOwnType(): void
    {
        $rows = $this->connection
            ->select('id', Expression::of('SUM(`score`) OVER (PARTITION BY `status`) * ? AS scaled', [2]))
            ->from('users')
            ->orderBy('id')
            ->get();

        // MySQL answers with the DECIMAL the multiplication produces, which PDO
        // hands back as a string of the server's scale. MariaDB answers with a
        // DOUBLE. A caller reading the column gets a different PHP type on each
        // server, so anything comparing it has to cast first. Pinned rather
        // than loosely compared so the difference stays visible.
        $expected = $this->connection->dialect() === Dialect::MySQL
            ? ['70.000000000000000000000000000000', '70.000000000000000000000000000000', '80.000000000000000000000000000000']
            : [70.0, 70.0, 80.0];

        $this->assertSame($expected, array_column($rows, 'scaled'));
    }

    public function testNeitherServerAllowsAWindowFunctionInWhere(): void
    {
        // SQL puts WHERE before the window functions are computed, so no server
        // can answer this. The builder does not check for it: the refusal is
        // the server's, and it names the function.
        $thrown = $this->assertThrows(
            QueryException::class,
            fn (): array => $this->connection
                ->select('id')
                ->from('users')
                ->where(Expression::of('ROW_NUMBER() OVER (ORDER BY `id`)'), '=', 1)
                ->get(),
        );

        $this->assertStringContainsString(
            $this->connection->dialect() === Dialect::MySQL ? '3593' : '4015',
            $thrown->getMessage(),
        );
    }

    public function testAWindowFunctionMayDecideTheOrderOfTheRows(): void
    {
        // The other place both servers accept one. MariaDB says so in the very
        // message it refuses a WHERE with: 'allowed only in SELECT list and
        // ORDER BY clause'.
        $rows = $this->connection
            ->select('id')
            ->from('users')
            ->orderBy(Expression::of('ROW_NUMBER() OVER (ORDER BY `score` DESC)'))
            ->get();

        $this->assertSame([3, 2, 1], array_column($rows, 'id'));
    }

    public function testANamedWindowIsReachableThroughARawQuery(): void
    {
        // The builder has no slot for a WINDOW clause, which sits between
        // HAVING and ORDER BY. Naming a window is therefore written out in
        // full, and the same result can always be had by repeating the
        // OVER (...) at each use instead.
        $rows = $this->connection->query(
            'SELECT `id`, ROW_NUMBER() OVER w AS rn FROM `users` WINDOW w AS (ORDER BY `score` DESC) ORDER BY `id`',
        )->asArray();

        $this->assertSame([['id' => 1, 'rn' => 3], ['id' => 2, 'rn' => 2], ['id' => 3, 'rn' => 1]], $rows);
    }
}
