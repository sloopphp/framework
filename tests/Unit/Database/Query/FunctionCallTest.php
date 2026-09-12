<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\FunctionCall;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\WindowExpression;
use Sloop\Tests\Support\ThrowsAssertions;

// The named factories on Expression are the fluent way into a window call:
// each names one function and takes its arguments, and over() turns that into
// the WindowExpression a Grammar compiles. What is pinned here is the spelling
// each factory writes, where its arguments land, and that a call reaches the
// grammar with the same guarantees Expression::over() gives it.
final class FunctionCallTest extends TestCase
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

        $this->connection = new Connection($sqlite, 'function_call_test');
    }

    #[DataProvider('provideNamedFactories')]
    public function testEachNamedFactoryWritesItsOwnCall(FunctionCall $call, string $expectedCall): void
    {
        $compiled = $this->connection->select([$call->over(), 'v'])->from('orders')->compile();

        $this->assertSame('SELECT ' . $expectedCall . ' OVER () AS `v` FROM `orders`', $compiled->sql);
    }

    /**
     * @return array<string, array{FunctionCall, string}>
     */
    public static function provideNamedFactories(): array
    {
        return [
            'cumeDist'       => [Expression::cumeDist(), 'CUME_DIST()'],
            'denseRank'      => [Expression::denseRank(), 'DENSE_RANK()'],
            'percentRank'    => [Expression::percentRank(), 'PERCENT_RANK()'],
            'rank'           => [Expression::rank(), 'RANK()'],
            'rowNumber'      => [Expression::rowNumber(), 'ROW_NUMBER()'],
            'firstValue'     => [Expression::firstValue('amount'), 'FIRST_VALUE(`amount`)'],
            'lastValue'      => [Expression::lastValue('amount'), 'LAST_VALUE(`amount`)'],
            'avg'            => [Expression::avg('amount'), 'AVG(`amount`)'],
            'bitAnd'         => [Expression::bitAnd('amount'), 'BIT_AND(`amount`)'],
            'bitOr'          => [Expression::bitOr('amount'), 'BIT_OR(`amount`)'],
            'bitXor'         => [Expression::bitXor('amount'), 'BIT_XOR(`amount`)'],
            'jsonArrayAgg'   => [Expression::jsonArrayAgg('amount'), 'JSON_ARRAYAGG(`amount`)'],
            'max'            => [Expression::max('amount'), 'MAX(`amount`)'],
            'min'            => [Expression::min('amount'), 'MIN(`amount`)'],
            'std'            => [Expression::std('amount'), 'STD(`amount`)'],
            'stddev'         => [Expression::stddev('amount'), 'STDDEV(`amount`)'],
            'stddevPop'      => [Expression::stddevPop('amount'), 'STDDEV_POP(`amount`)'],
            'stddevSamp'     => [Expression::stddevSamp('amount'), 'STDDEV_SAMP(`amount`)'],
            'sum'            => [Expression::sum('amount'), 'SUM(`amount`)'],
            'varPop'         => [Expression::varPop('amount'), 'VAR_POP(`amount`)'],
            'varSamp'        => [Expression::varSamp('amount'), 'VAR_SAMP(`amount`)'],
            'variance'       => [Expression::variance('amount'), 'VARIANCE(`amount`)'],
            'count default'  => [Expression::count(), 'COUNT(*)'],
            'count column'   => [Expression::count('amount'), 'COUNT(`amount`)'],
            'jsonObjectAgg'  => [Expression::jsonObjectAgg('status', 'amount'), 'JSON_OBJECTAGG(`status`, `amount`)'],
            'lag column'     => [Expression::lag('amount'), 'LAG(`amount`)'],
            'lead column'    => [Expression::lead('amount'), 'LEAD(`amount`)'],
            'qualified name' => [Expression::sum('orders.amount'), 'SUM(`orders`.`amount`)'],
            'expression arg' => [Expression::sum(Expression::of('`amount` * ?', [2])), 'SUM(`amount` * ?)'],
        ];
    }

    public function testEveryFunctionTheGrammarWritesHasANamedFactory(): void
    {
        $grammar = new class () extends Grammar {
            /**
             * @return list<string> Names this grammar writes
             */
            public function namesItWrites(): array
            {
                return $this->windowFunctions();
            }
        };

        $named = array_map(
            static fn (array $case): string => $case[0]->function,
            array_values(self::provideNamedFactories()),
        );
        // The two the table above leaves out, because their arguments are
        // neither absent nor a single column.
        $named[] = Expression::ntile(4)->function;
        $named[] = Expression::nthValue('amount', 2)->function;

        $missing = array_values(array_diff($grammar->namesItWrites(), $named));

        $this->assertSame([], $missing);
    }

    public function testNtileBindsItsBucketCount(): void
    {
        $compiled = $this->connection->select([Expression::ntile(4)->over(), 'v'])->from('orders')->compile();

        $this->assertSame('SELECT NTILE(?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([4], $compiled->bindings);
    }

    public function testNthValueBindsItsPositionAndQuotesItsColumn(): void
    {
        $compiled = $this->connection->select([Expression::nthValue('amount', 2)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT NTH_VALUE(`amount`, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([2], $compiled->bindings);
    }

    public function testLagWritesNoOffsetWhenNoneIsGiven(): void
    {
        $compiled = $this->connection->select([Expression::lag('amount')->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LAG(`amount`) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testLagWritesTheOffsetItIsGiven(): void
    {
        $compiled = $this->connection->select([Expression::lag('amount', 2)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LAG(`amount`, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([2], $compiled->bindings);
    }

    public function testLagWritesADefaultOnlyWhenOneIsGiven(): void
    {
        $compiled = $this->connection->select([Expression::lag('amount', 1, 0)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LAG(`amount`, ?, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([1, 0], $compiled->bindings);
    }

    public function testLagWritesAnExplicitNullDefault(): void
    {
        $compiled = $this->connection->select([Expression::lag('amount', 1, null)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LAG(`amount`, ?, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([1, null], $compiled->bindings);
    }

    public function testNamingADefaultWritesTheOffsetItSkipped(): void
    {
        // SQL takes its arguments by position, so a default cannot be written
        // without an offset before it. PHP fills the skipped slot with the
        // default and counts it in func_num_args(), which is what puts the 1
        // in the statement -- the same offset the server would have used.
        $compiled = $this->connection->select([Expression::lag('amount', default: 0)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LAG(`amount`, ?, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([1, 0], $compiled->bindings);
    }

    public function testLeadWritesTheOffsetWhenOnlyADefaultIsNamed(): void
    {
        $compiled = $this->connection->select([Expression::lead('amount', default: 0)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LEAD(`amount`, ?, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([1, 0], $compiled->bindings);
    }

    public function testAStringDefaultNamesAColumn(): void
    {
        // The arguments are read by type wherever they sit, so a string given
        // as the default is quoted as a column rather than bound. A literal
        // string goes in as an Expression.
        $compiled = $this->connection->select([Expression::lag('amount', 1, 'fallback')->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LAG(`amount`, ?, `fallback`) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([1], $compiled->bindings);
    }

    public function testALiteralStringDefaultGoesInAsAnExpression(): void
    {
        $compiled = $this->connection
            ->select([Expression::lag('amount', 1, Expression::of('?', ['n/a']))->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LAG(`amount`, ?, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([1, 'n/a'], $compiled->bindings);
    }

    public function testLeadWritesTheSameArgumentsAsLag(): void
    {
        $compiled = $this->connection->select([Expression::lead('amount', 3, 0)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LEAD(`amount`, ?, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([3, 0], $compiled->bindings);
    }

    public function testLeadWritesNoDefaultWhenOnlyAnOffsetIsGiven(): void
    {
        $compiled = $this->connection->select([Expression::lead('amount', 3)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LEAD(`amount`, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([3], $compiled->bindings);
    }

    public function testTheGeneralFactoryNamesAnyFunctionTheGrammarWrites(): void
    {
        $compiled = $this->connection->select([Expression::fn('SUM', 'amount')->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT SUM(`amount`) OVER () AS `v` FROM `orders`', $compiled->sql);
    }

    public function testTheGeneralFactoryTakesSeveralArgumentsInWrittenOrder(): void
    {
        $compiled = $this->connection->select([Expression::fn('LAG', 'amount', 2, 0)->over(), 'v'])
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT LAG(`amount`, ?, ?) OVER () AS `v` FROM `orders`', $compiled->sql);
        $this->assertSame([2, 0], $compiled->bindings);
    }

    public function testAFunctionTheGrammarDoesNotWriteIsRefusedWhereItIsNamed(): void
    {
        $window = Expression::fn('GROUP_CONCAT', 'amount')->over();

        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): mixed => $this->connection->select([$window, 'v']),
        );

        $this->assertSame(
            'This grammar writes no window function called GROUP_CONCAT. Add it by overriding windowFunctions().',
            $thrown->getMessage(),
        );
    }

    public function testAnEmptyFunctionNameIsRefused(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn (): FunctionCall => Expression::fn('  '));
    }

    public function testOverTurnsTheCallIntoAWindowExpression(): void
    {
        $window = Expression::sum('amount')->over();

        $this->assertInstanceOf(WindowExpression::class, $window);
        $this->assertSame('SUM', $window->function);
        $this->assertSame(['amount'], $window->arguments);
        $this->assertSame([], $window->partitions);
        $this->assertSame([], $window->orders);
    }

    public function testACallHoldsItsFunctionAndArgumentsBeforeItIsGivenAWindow(): void
    {
        $call = Expression::lag('amount', 2);

        $this->assertSame('LAG', $call->function);
        $this->assertSame(['amount', 2], $call->arguments);
    }

    public function testTheFunctionNameIsTrimmed(): void
    {
        $this->assertSame('SUM', Expression::fn('  SUM  ')->function);
    }
}
