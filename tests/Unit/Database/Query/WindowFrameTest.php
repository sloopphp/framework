<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\SelectSpec;
use Sloop\Database\Query\WindowExpression;
use Sloop\Database\Query\WindowFrame;
use Sloop\Tests\Support\ThrowsAssertions;

// rows() and range() write a frame from signed offsets: negative counts back,
// positive counts forward, zero is the current row and null is unbounded. The
// offsets go into the SQL as written numbers because MariaDB refuses a
// placeholder there, so what is pinned here is the text each pair becomes and
// the shapes both servers refuse, which the builder refuses first.
final class WindowFrameTest extends TestCase
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
        $sqlite->createFunction('version', static fn (): string => '8.0.37');
        $sqlite->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, amount INTEGER NOT NULL)');
        $sqlite->exec('INSERT INTO orders (id, amount) VALUES (1, 10), (2, 20), (3, 30), (4, 40)');

        $this->connection = new Connection($sqlite, 'frame_test');
    }

    private function runningSum(): WindowExpression
    {
        return Expression::sum('amount')->over()->orderBy('id');
    }

    /**
     * @return array<string, array{int|null, int|null, string}>
     */
    public static function boundsProvider(): array
    {
        return [
            'three back to the current row'   => [-3, 0, '3 PRECEDING AND CURRENT ROW'],
            'the first row to the current'    => [null, 0, 'UNBOUNDED PRECEDING AND CURRENT ROW'],
            'one ahead to three ahead'        => [1, 3, '1 FOLLOWING AND 3 FOLLOWING'],
            'two either side'                 => [-2, 2, '2 PRECEDING AND 2 FOLLOWING'],
            'the current row to the last'     => [0, null, 'CURRENT ROW AND UNBOUNDED FOLLOWING'],
            'every row of the partition'      => [null, null, 'UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING'],
            'three back to one back'          => [-3, -1, '3 PRECEDING AND 1 PRECEDING'],
            'the current row alone'           => [0, 0, 'CURRENT ROW AND CURRENT ROW'],
            'the most negative int, unsigned' => [PHP_INT_MIN, 0, substr((string) PHP_INT_MIN, 1) . ' PRECEDING AND CURRENT ROW'],
        ];
    }

    #[DataProvider('boundsProvider')]
    public function testRowsWritesEachSignedOffsetAsItsBound(?int $start, ?int $end, string $between): void
    {
        $compiled = $this->connection->select([$this->runningSum()->rows($start, $end), 'v'])->from('orders')->compile();

        $this->assertSame(
            'SELECT SUM(`amount`) OVER (ORDER BY `id` ASC ROWS BETWEEN ' . $between . ') AS `v` FROM `orders`',
            $compiled->sql,
        );
        $this->assertSame([], $compiled->bindings);
    }

    public function testRangeWritesTheSameBoundsUnderItsOwnKeyword(): void
    {
        $compiled = $this->connection->select([$this->runningSum()->range(-10, 0), 'v'])->from('orders')->compile();

        $this->assertSame(
            'SELECT SUM(`amount`) OVER (ORDER BY `id` ASC RANGE BETWEEN 10 PRECEDING AND CURRENT ROW) AS `v` FROM `orders`',
            $compiled->sql,
        );
    }

    public function testTheFrameComesAfterThePartitionsAndTheSortTerms(): void
    {
        $window = Expression::sum('amount')->over()->partitionBy('id')->orderBy('id')->rows(-1, 0);

        $this->assertSame(
            'SELECT SUM(`amount`) OVER (PARTITION BY `id` ORDER BY `id` ASC ROWS BETWEEN 1 PRECEDING AND CURRENT ROW) FROM `orders`',
            $this->connection->select($window)->from('orders')->toSql(),
        );
    }

    public function testTheFrameSurvivesAPartitionOrSortTermAddedAfterIt(): void
    {
        $window = Expression::sum('amount')->over()->rows(-1, 0)->partitionBy('id')->orderBy('id');

        $this->assertSame(
            'SELECT SUM(`amount`) OVER (PARTITION BY `id` ORDER BY `id` ASC ROWS BETWEEN 1 PRECEDING AND CURRENT ROW) FROM `orders`',
            $this->connection->select($window)->from('orders')->toSql(),
        );
    }

    public function testASecondFrameReplacesTheFirst(): void
    {
        $window = $this->runningSum()->rows(-1, 0)->range(null, 0);

        $this->assertStringEndsWith(
            'ORDER BY `id` ASC RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) FROM `orders`',
            $this->connection->select($window)->from('orders')->toSql(),
        );
    }

    public function testSettingAFrameLeavesTheWindowItWasCalledOnAlone(): void
    {
        $window = $this->runningSum();
        $window->rows(-1, 0);

        $this->assertNull($window->frame);
    }

    public function testTheRowsFrameSumsThePreviousRowAndTheCurrentOne(): void
    {
        $rows = $this->connection
            ->select('id', [$this->runningSum()->rows(-1, 0), 'v'])
            ->from('orders')
            ->orderBy('id')
            ->execute()
            ->asArray();

        $this->assertSame([10, 30, 50, 70], array_column($rows, 'v'));
    }

    public function testAStartAfterTheEndIsRefused(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, fn () => $this->runningSum()->rows(2, 1));

        $this->assertSame(
            'A frame has to start at or before where it ends; 2 FOLLOWING comes after 1 FOLLOWING.',
            $e->getMessage(),
        );
    }

    public function testAnUnboundedStartIsNeverAfterTheEnd(): void
    {
        $this->assertNotNull($this->runningSum()->rows(null, PHP_INT_MIN)->frame);
    }

    public function testAnUnboundedEndIsNeverBeforeTheStart(): void
    {
        $this->assertNotNull($this->runningSum()->rows(PHP_INT_MAX, null)->frame);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function framelessFunctionProvider(): array
    {
        return [
            'ROW_NUMBER'           => ['ROW_NUMBER'],
            'RANK'                 => ['RANK'],
            'DENSE_RANK'           => ['DENSE_RANK'],
            'PERCENT_RANK'         => ['PERCENT_RANK'],
            'CUME_DIST'            => ['CUME_DIST'],
            'NTILE'                => ['NTILE'],
            'LAG'                  => ['LAG'],
            'LEAD'                 => ['LEAD'],
            'lower case, trimmed'  => [' row_number '],
        ];
    }

    #[DataProvider('framelessFunctionProvider')]
    public function testAFrameOnAFunctionThatIgnoresItIsRefused(string $function): void
    {
        $window = new WindowExpression($function);

        $e = $this->assertThrows(InvalidArgumentException::class, fn () => $window->rows(-1, 0));

        $this->assertSame(
            strtoupper(trim($function)) . '() does not read a frame; MySQL ignores one and MariaDB refuses it.',
            $e->getMessage(),
        );
    }

    public function testAFrameOnAnAggregateIsAccepted(): void
    {
        $this->assertNotNull(new WindowExpression('FIRST_VALUE', ['amount'])->rows(-1, 0)->frame);
    }

    /**
     * @return array<string, array{int|null, int|null}>
     */
    public static function rangeOffsetProvider(): array
    {
        return [
            'an offset at the start' => [-10, 0],
            'an offset at the end'   => [0, 10],
            'one row back'           => [-1, 0],
            'one row ahead'          => [0, 1],
        ];
    }

    #[DataProvider('rangeOffsetProvider')]
    public function testARangeOffsetWithoutASortTermIsRefused(?int $start, ?int $end): void
    {
        $window = Expression::sum('amount')->over()->range($start, $end);

        $e = $this->assertThrows(LogicException::class, fn () => $this->connection->select($window)->from('orders')->toSql());

        $this->assertSame(
            'A RANGE frame with an offset measures it along exactly one sort term, and this window has 0.',
            $e->getMessage(),
        );
    }

    public function testARangeOffsetWithTwoSortTermsIsRefused(): void
    {
        $window = Expression::sum('amount')->over()->orderBy('id')->orderBy('amount')->range(-10, 0);

        $e = $this->assertThrows(LogicException::class, fn () => $this->connection->select($window)->from('orders')->toSql());

        $this->assertSame(
            'A RANGE frame with an offset measures it along exactly one sort term, and this window has 2.',
            $e->getMessage(),
        );
    }

    public function testARangeWithoutAnOffsetNeedsNoSortTerm(): void
    {
        $window = Expression::sum('amount')->over()->range(null, 0);

        $this->assertSame(
            'SELECT SUM(`amount`) OVER (RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) FROM `orders`',
            $this->connection->select($window)->from('orders')->toSql(),
        );
    }

    public function testASubclassCanReplaceTheFrameClause(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileFrame(WindowFrame $frame, int $sortTerms): string
            {
                return $frame->unit->value . ' (' . $sortTerms . ' terms)';
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec('orders', [$this->runningSum()->rows(-1, 0)]));

        $this->assertSame('SELECT SUM(`amount`) OVER (ORDER BY `id` ASC ROWS (1 terms)) FROM `orders`', $compiled->sql);
    }

    public function testARowsOffsetNeedsNoSortTerm(): void
    {
        $window = Expression::sum('amount')->over()->rows(-1, 0);

        $this->assertSame(
            'SELECT SUM(`amount`) OVER (ROWS BETWEEN 1 PRECEDING AND CURRENT ROW) FROM `orders`',
            $this->connection->select($window)->from('orders')->toSql(),
        );
    }
}
