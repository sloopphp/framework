<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;

// Window functions have no builder of their own: they are written with
// Expression and reach the statement through the ordinary select list and
// ORDER BY. Nothing in src names them, so a change to the clause order, to
// compileColumns() or to how an Expression carries its bindings could stop
// them working without any test noticing. These pin that they still do.
final class SelectWindowTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->createFunction('version', static fn (): string => '8.0.37');
        $sqlite->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL,'
            . ' status TEXT NOT NULL, amount INTEGER NOT NULL)');
        $sqlite->exec('INSERT INTO orders (id, user_id, status, amount) VALUES'
            . " (1, 10, 'paid', 100), (2, 10, 'paid', 250), (3, 20, 'open', 40)");

        $this->connection = new Connection($sqlite, 'window_test');
    }

    public function testAWindowExpressionInTheSelectListIsWrittenAsItWasGiven(): void
    {
        $compiled = $this->connection
            ->select('id', Expression::of('ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `amount` DESC) AS rn'))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT `id`, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `amount` DESC) AS rn FROM `orders`',
            $compiled->sql,
        );
        $this->assertSame([], $compiled->bindings);
    }

    public function testAWindowExpressionKeepsItsBindingsAheadOfTheOnesInWhere(): void
    {
        // The select list is compiled before the where clause, so a bound value
        // inside a window expression has to arrive first. Getting this wrong
        // pairs each value with the wrong placeholder rather than failing.
        $compiled = $this->connection
            ->select('id', Expression::of('SUM(`amount`) OVER (PARTITION BY `user_id`) * ? AS scaled', [2]))
            ->from('orders')
            ->where('status', '=', 'paid')
            ->compile();

        $this->assertSame(
            'SELECT `id`, SUM(`amount`) OVER (PARTITION BY `user_id`) * ? AS scaled FROM `orders` WHERE `status` = ?',
            $compiled->sql,
        );
        $this->assertSame([2, 'paid'], $compiled->bindings);
    }

    public function testTheRowNumbersStartOverInEachPartition(): void
    {
        $rows = $this->connection
            ->select('id', Expression::of('ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `amount` DESC) AS rn'))
            ->from('orders')
            ->orderBy('id')
            ->get();

        $this->assertSame([['id' => 1, 'rn' => 2], ['id' => 2, 'rn' => 1], ['id' => 3, 'rn' => 1]], $rows);
    }

    public function testAWindowExpressionCanDecideTheOrderOfTheRows(): void
    {
        $rows = $this->connection
            ->select('id')
            ->from('orders')
            ->orderBy(Expression::of('ROW_NUMBER() OVER (ORDER BY `amount` DESC)'))
            ->get();

        $this->assertSame([2, 1, 3], array_column($rows, 'id'));
    }

    public function testSelectRawWritesAWindowExpressionWithoutNamingExpression(): void
    {
        $compiled = $this->connection
            ->select('id')
            ->selectRaw('SUM(`amount`) OVER (PARTITION BY `user_id`) AS total', [])
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT `id`, SUM(`amount`) OVER (PARTITION BY `user_id`) AS total FROM `orders`',
            $compiled->sql,
        );
    }
}
