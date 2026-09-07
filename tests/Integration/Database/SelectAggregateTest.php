<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Sloop\Database\CastMode;
use Sloop\Database\Dialect;
use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests run against SQLite, which decides the type of an aggregate for
// itself. What only a real server can answer is what type MySQL and MariaDB
// give each call back as, since that is what a caller receives.
final class SelectAggregateTest extends TransactionalIntegrationTestCase
{
    private const string WIDE_TABLE = 'sloop_aggregate_wide';

    protected static function setUpSharedFixtures(): void
    {
        $connection = static::openConnection();
        $connection->statement('DROP TABLE IF EXISTS ' . self::WIDE_TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::WIDE_TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'wide BIGINT UNSIGNED NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection->statement(
            'INSERT INTO users (id, name, email, status, score, created_at, deleted_at) VALUES'
                . ' (1, ?, ?, ?, ?, ?, NULL),'
                . ' (2, ?, ?, ?, ?, ?, ?),'
                . ' (3, ?, ?, ?, ?, ?, NULL)',
            [
                'alice', 'alice@example.com', 'active', 10, '2020-01-01 10:00:00',
                'bob', 'bob@example.com', 'active', 25, '2021-06-15 12:30:00', '2022-03-03 03:03:03',
                'carol', 'carol@example.com', 'blocked', 40, '2022-12-31 23:59:59',
            ],
        );
    }

    public function testSumOfAnIntegerColumnKeepsEveryDigitAsAString(): void
    {
        // Both servers answer a SUM over an integer column with a DECIMAL, and
        // PDO hands a DECIMAL back as a string. The value is left as it came so
        // that a total wider than a PHP int is not rounded into one.
        $this->assertSame('75', $this->connection->select()->from('users')->sum('score'));
    }

    public function testAvgOfAnIntegerColumnComesBackWithTheServersScale(): void
    {
        $this->assertSame(
            '17.5000',
            $this->connection->select()->from('users')->where('status', 'active')->avg('score'),
        );
    }

    public function testMinAndMaxKeepTheTypeOfTheColumnTheyRead(): void
    {
        $select = $this->connection->select()->from('users');

        $this->assertSame(10, $select->min('score'));
        $this->assertSame(40, $select->max('score'));
    }

    public function testMinAndMaxOfADatetimeColumnReadAsTheDriverGivesThem(): void
    {
        $select = $this->connection->select()->from('users');

        $this->assertSame('2020-01-01 10:00:00', $select->min('created_at'));
        $this->assertSame('2022-12-31 23:59:59', $select->max('created_at'));
    }

    public function testMinOfADatetimeColumnBecomesAnObjectUnderTheDatetimeCast(): void
    {
        // The aggregate keeps the column's native type, so the preset reaches
        // it the same way it reaches the column itself.
        $value = $this->connection->select()->from('users')->castMode(CastMode::Datetime)->min('created_at');

        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2020-01-01 10:00:00', $value->format('Y-m-d H:i:s'));
    }

    public function testSumOfWideValuesCountsPastWhatAPhpIntHolds(): void
    {
        $this->connection->statement(
            'INSERT INTO ' . self::WIDE_TABLE . ' (wide) VALUES (?), (?)',
            [PHP_INT_MAX, 2],
        );

        // 9223372036854775807 + 2, which no PHP int can hold.
        $this->assertSame(
            '9223372036854775809',
            $this->connection->select()->from(self::WIDE_TABLE)->sum('wide'),
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function aggregateMethods(): array
    {
        return [['sum'], ['avg'], ['min'], ['max']];
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateIsNullWhenNothingMatched(string $method): void
    {
        $value = $this->connection->select()->from('users')->where('status', 'missing')->{$method}('score');

        $this->assertNull($value);
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateIsNullWhenEveryMatchingRowHoldsNull(string $method): void
    {
        // An aggregate skips the rows holding null, so matching only those
        // leaves it nothing to read rather than a zero.
        $value = $this->connection->select()->from('users')->whereNull('deleted_at')->{$method}('deleted_at');

        $this->assertNull($value);
    }

    public function testAggregateSkipsTheRowsHoldingNullRatherThanCountingThem(): void
    {
        // Two of the three rows hold null, and the average is the remaining
        // value rather than a third of it.
        $this->assertSame(
            '2022-03-03 03:03:03',
            $this->connection->select()->from('users')->min('deleted_at'),
        );
    }

    public function testAggregateTakesAnExpressionWithItsOwnBindings(): void
    {
        $value = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->sum(Expression::of('`score` * ?', [2]));

        // The total is the same on either server; the type it arrives as is
        // not. MariaDB settles the type of an expression holding a placeholder
        // when the statement is prepared, before the parameter's type is
        // known, and answers DOUBLE. MySQL keeps the DECIMAL the values
        // produce. Pinned rather than compared loosely so that the difference
        // stays visible to whoever reads what sum() can return.
        $this->assertSame($this->connection->dialect() === Dialect::MySQL ? '70' : 70.0, $value);
    }

    public function testTheServerAnswersAGroupedAggregateOneGroupAtATime(): void
    {
        // The premise the refusal rests on, written against the server rather
        // than the builder: the first row holds the first group's total, and
        // nothing in the answer says it is one of several. SelectAggregateTest
        // in tests/Unit pins the refusal itself, which is decided before a
        // connection is asked for.
        $rows = $this->connection->select('status', Expression::of('SUM(`score`) AS total'))
            ->from('users')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $this->assertSame('35', $rows[0]['total'] ?? null);
        $this->assertSame('40', $rows[1]['total'] ?? null);
    }
}
