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
use Sloop\Database\Query\CompiledSql;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\FunctionCall;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\SelectedColumn;
use Sloop\Database\Query\SelectSpec;
use Sloop\Tests\Support\ThrowsAssertions;

// A function call placed in the select list without over() is an aggregate. It
// is compiled the way a window call is -- the columns quoted, the prefix
// applied -- but checked against the grammar's list of aggregates, which holds
// GROUP_CONCAT and leaves out the functions that only run over a window.
final class AggregateCallTest extends TestCase
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
        $sqlite->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL,'
            . ' status TEXT NOT NULL, amount INTEGER NOT NULL)');
        $sqlite->exec('INSERT INTO orders (id, user_id, status, amount) VALUES'
            . " (1, 10, 'paid', 100), (2, 10, 'paid', 250), (3, 20, 'open', 40)");

        $this->connection = new Connection($sqlite, 'aggregate_test');
    }

    public function testAnAggregateInTheSelectListQuotesItsColumn(): void
    {
        $compiled = $this->connection->select(Expression::sum('amount'))->from('orders')->compile();

        $this->assertSame('SELECT SUM(`amount`) FROM `orders`', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testAnAggregateTakesANameAsAPair(): void
    {
        $compiled = $this->connection->select([Expression::count(), 'n'])->from('orders')->compile();

        $this->assertSame('SELECT COUNT(*) AS `n` FROM `orders`', $compiled->sql);
    }

    public function testAnAggregateReadsTheGroupsOfAGroupedStatement(): void
    {
        $rows = $this->connection
            ->select('user_id', [Expression::sum('amount'), 'total'], [Expression::count(), 'orders'])
            ->from('orders')
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->execute()
            ->asArray();

        $this->assertSame(
            [['user_id' => 10, 'total' => 350, 'orders' => 2], ['user_id' => 20, 'total' => 40, 'orders' => 1]],
            $rows,
        );
    }

    public function testTheTablePrefixReachesTheColumnsOfAnAggregate(): void
    {
        $compiled = new Grammar('p_')->compileSelect(new SelectSpec('orders', [
            new SelectedColumn(Expression::sum('orders.amount'), 'total'),
        ]));

        $this->assertSame('SELECT SUM(`p_orders`.`amount`) AS `total` FROM `p_orders`', $compiled->sql);
    }

    public function testAnAggregateArgumentIsReadAsAColumnAValueOrAnExpressionByItsType(): void
    {
        $compiled = $this->connection
            ->select(Expression::fn('max', 'amount', Expression::of('? * 2', [3]), 5))
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT MAX(`amount`, ? * 2, ?) FROM `orders`', $compiled->sql);
        $this->assertSame([3, 5], $compiled->bindings);
    }

    public function testTheBindingsOfAnAggregateComeBeforeTheOnesInWhere(): void
    {
        $compiled = $this->connection
            ->select([Expression::sum(Expression::of('`amount` * ?', [2])), 'doubled'])
            ->from('orders')
            ->where('status', 'paid')
            ->compile();

        $this->assertSame('SELECT SUM(`amount` * ?) AS `doubled` FROM `orders` WHERE `status` = ?', $compiled->sql);
        $this->assertSame([2, 'paid'], $compiled->bindings);
    }

    public function testGroupConcatIsAnAggregate(): void
    {
        $compiled = $this->connection->select([Expression::groupConcat('status'), 'statuses'])->from('orders')->compile();

        $this->assertSame('SELECT GROUP_CONCAT(`status`) AS `statuses` FROM `orders`', $compiled->sql);
    }

    public function testGroupConcatIsNotAWindowFunction(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->connection->select(Expression::groupConcat('status')->over())->from('orders'),
        );

        $this->assertSame(
            'This grammar writes no window function called GROUP_CONCAT. Add it by overriding windowFunctions().',
            $e->getMessage(),
        );
    }

    /**
     * @return array<string, array{FunctionCall, string}>
     */
    public static function distinctProvider(): array
    {
        return [
            'COUNT'        => [Expression::count('user_id', distinct: true), 'COUNT(DISTINCT `user_id`)'],
            'SUM'          => [Expression::sum('amount', distinct: true), 'SUM(DISTINCT `amount`)'],
            'AVG'          => [Expression::avg('amount', distinct: true), 'AVG(DISTINCT `amount`)'],
            'MIN'          => [Expression::min('amount', distinct: true), 'MIN(DISTINCT `amount`)'],
            'MAX'          => [Expression::max('amount', distinct: true), 'MAX(DISTINCT `amount`)'],
            'GROUP_CONCAT' => [Expression::groupConcat('status', distinct: true), 'GROUP_CONCAT(DISTINCT `status`)'],
        ];
    }

    #[DataProvider('distinctProvider')]
    public function testAnAggregateWrittenDistinctReadsEachValueOnce(FunctionCall $call, string $expected): void
    {
        $compiled = $this->connection->select($call)->from('orders')->compile();

        $this->assertSame('SELECT ' . $expected . ' FROM `orders`', $compiled->sql);
    }

    public function testAnAggregateIsNotDistinctUnlessAsked(): void
    {
        $call = Expression::count('user_id');

        $this->assertFalse($call->distinct);
        $this->assertSame('SELECT COUNT(`user_id`) FROM `orders`', $this->connection->select($call)->from('orders')->compile()->sql);
    }

    public function testDistinctReadsTheRowsOnceEachValue(): void
    {
        $rows = $this->connection
            ->select([Expression::count('user_id', distinct: true), 'users'], [Expression::sum('amount', distinct: true), 'total'])
            ->from('orders')
            ->execute()
            ->asArray();

        $this->assertSame([['users' => 2, 'total' => 390]], $rows);
    }

    public function testCountingDistinctRowsIsRefused(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Expression::count(distinct: true));

        $this->assertSame(
            'DISTINCT reads the values of a column, so it takes no *. Name the column to read the distinct values of.',
            $e->getMessage(),
        );
    }

    public function testDistinctOverEveryColumnOfATableIsRefused(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Expression::sum('orders.*', distinct: true));

        $this->assertStringStartsWith('DISTINCT reads the values of a column, so it takes no *.', $e->getMessage());
    }

    public function testAColumnEndingInAStarIsNotMistakenForEveryColumn(): void
    {
        $call = Expression::count('total*', distinct: true);

        $this->assertTrue($call->distinct);
    }

    public function testASortTermThatIsNotAnOrderIsRefusedWhereTheCallIsBuilt(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => new FunctionCall('GROUP_CONCAT', ['status'], orders: ['status']));

        $this->assertSame('Orders must be an Order, got string at index 0.', $e->getMessage());
    }

    public function testADistinctCallTakesNoWindow(): void
    {
        $e = $this->assertThrows(LogicException::class, static fn () => Expression::count('user_id', distinct: true)->over());

        $this->assertSame(
            'A call written with DISTINCT, an ORDER BY, or a SEPARATOR takes no window; neither server accepts one.',
            $e->getMessage(),
        );
    }

    /**
     * @return array<string, array{FunctionCall}>
     */
    public static function groupConcatWithoutWindowProvider(): array
    {
        return [
            'a sort order' => [Expression::groupConcat('status', orders: ['status'])],
            'a separator'  => [Expression::groupConcat('status', separator: '|')],
        ];
    }

    #[DataProvider('groupConcatWithoutWindowProvider')]
    public function testAGroupConcatWithASortOrderOrSeparatorTakesNoWindow(FunctionCall $call): void
    {
        $this->assertThrows(LogicException::class, static fn () => $call->over());
    }

    public function testGroupConcatSortsItsValuesAndJoinsThemWithTheSeparator(): void
    {
        $compiled = $this->connection
            ->select(Expression::groupConcat('status', distinct: true, orders: ['status' => 'DESC', 'id'], separator: '|'))
            ->from('orders')
            ->compile();

        $this->assertSame(
            "SELECT GROUP_CONCAT(DISTINCT `status` ORDER BY `status` DESC, `id` ASC SEPARATOR '|') FROM `orders`",
            $compiled->sql,
        );
        $this->assertSame([], $compiled->bindings);
    }

    public function testGroupConcatWritesNoSeparatorWhenNoneIsGiven(): void
    {
        $call = Expression::groupConcat('status', orders: ['status']);

        $this->assertNull($call->separator);
        $this->assertSame(
            'SELECT GROUP_CONCAT(`status` ORDER BY `status` ASC) FROM `orders`',
            $this->connection->select($call)->from('orders')->compile()->sql,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function separatorProvider(): array
    {
        return [
            'a quote'         => ["'", "''''"],
            'two quotes'      => ["a''b", "'a''''b'"],
            'several bytes'   => ['・', "'・'"],
            'an empty string' => ['', "''"],
            'a question mark' => ['?', "'?'"],
        ];
    }

    #[DataProvider('separatorProvider')]
    public function testTheSeparatorIsWrittenAsAQuotedLiteral(string $separator, string $expected): void
    {
        $compiled = $this->connection->select(Expression::groupConcat('status', separator: $separator))->from('orders')->compile();

        $this->assertSame('SELECT GROUP_CONCAT(`status` SEPARATOR ' . $expected . ') FROM `orders`', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testASeparatorHoldingABackslashIsRefused(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Expression::groupConcat('status', separator: 'a\\b'));

        $this->assertSame(
            'A separator may not hold a backslash; it reads as an escape or as itself depending on the SQL mode.',
            $e->getMessage(),
        );
    }

    public function testAQuestionMarkInTheSeparatorIsNotReadAsAPlaceholder(): void
    {
        $raw = $this->connection
            ->select(Expression::groupConcat('status', separator: "'?"))
            ->from('orders')
            ->where('user_id', 10)
            ->toRawSql();

        $this->assertSame("SELECT GROUP_CONCAT(`status` SEPARATOR '''?') FROM `orders` WHERE `user_id` = '10'", $raw);
    }

    public function testTheBindingsOfTheSortTermsComeAfterTheOnesOfTheArgument(): void
    {
        $compiled = $this->connection
            ->select(Expression::groupConcat(Expression::of('`status` + ?', [1]), orders: [Expression::of('`amount` * ?', [2])]))
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT GROUP_CONCAT(`status` + ? ORDER BY `amount` * ?) FROM `orders`', $compiled->sql);
        $this->assertSame([1, 2], $compiled->bindings);
    }

    public function testTheTablePrefixReachesTheSortTermsOfAGroupConcat(): void
    {
        $compiled = new Grammar('p_')->compileSelect(new SelectSpec('orders', [
            Expression::groupConcat('orders.status', orders: ['orders.id' => 'DESC']),
        ]));

        $this->assertSame(
            'SELECT GROUP_CONCAT(`p_orders`.`status` ORDER BY `p_orders`.`id` DESC) FROM `p_orders`',
            $compiled->sql,
        );
    }

    public function testASortTermOfAGroupConcatIsReadLikeOneOfAWindow(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => Expression::groupConcat('status', orders: ['DESC']));

        $this->assertStringStartsWith('A sort direction stands where a column is named', $e->getMessage());
    }

    public function testTheNameIsWrittenInTheGrammarsSpelling(): void
    {
        $compiled = $this->connection->select(Expression::fn(' group_concat ', 'status'))->from('orders')->compile();

        $this->assertSame('SELECT GROUP_CONCAT(`status`) FROM `orders`', $compiled->sql);
    }

    /**
     * @return array<string, array{FunctionCall, string}>
     */
    public static function windowOnlyProvider(): array
    {
        return [
            'ROW_NUMBER'  => [Expression::rowNumber(), 'ROW_NUMBER'],
            'RANK'        => [Expression::rank(), 'RANK'],
            'LAG'         => [Expression::lag('amount'), 'LAG'],
            'FIRST_VALUE' => [Expression::firstValue('amount'), 'FIRST_VALUE'],
            'NTILE'       => [Expression::ntile(2), 'NTILE'],
        ];
    }

    #[DataProvider('windowOnlyProvider')]
    public function testAFunctionThatOnlyRunsOverAWindowIsRefusedAsAnAggregate(FunctionCall $call, string $name): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->connection->select($call)->from('orders'),
        );

        $this->assertSame(
            'This grammar writes no aggregate function called ' . $name
            . '. Give it a window with over(), or add it by overriding aggregateFunctions().',
            $e->getMessage(),
        );
    }

    public function testAWindowOnlyFunctionIsRefusedInAPairToo(): void
    {
        $e = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->connection->select([Expression::rowNumber(), 'rn'])->from('orders'),
        );

        $this->assertSame(
            'This grammar writes no aggregate function called ROW_NUMBER.'
            . ' Give it a window with over(), or add it by overriding aggregateFunctions().',
            $e->getMessage(),
        );
    }

    public function testASubclassCanReplaceHowAnAggregateIsWritten(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileAggregate(FunctionCall $call): CompiledSql
            {
                return new CompiledSql('AGG(' . $call->function . ')');
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec('orders', [Expression::sum('amount')]));

        $this->assertSame('SELECT AGG(SUM) FROM `orders`', $compiled->sql);
    }

    public function testASubclassCanReplaceHowTheArgumentsOfACallAreWritten(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileArguments(array $arguments): CompiledSql
            {
                return new CompiledSql(\count($arguments) . ' args');
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec('orders', [
            Expression::sum('amount'),
            Expression::count()->over(),
        ]));

        $this->assertSame('SELECT SUM(1 args), COUNT(1 args) OVER () FROM `orders`', $compiled->sql);
    }

    public function testASubclassCanAddAnAggregate(): void
    {
        $grammar = new class () extends Grammar {
            protected function aggregateFunctions(): array
            {
                return [...parent::aggregateFunctions(), 'ANY_VALUE'];
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec('orders', [Expression::fn('any_value', 'status')]));

        $this->assertSame('SELECT ANY_VALUE(`status`) FROM `orders`', $compiled->sql);
    }
}
