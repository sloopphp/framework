<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Exception\QueryException;
use Sloop\Database\LoggingOptions;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;

final class SelectAggregateTest extends TestCase
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
            . ' status TEXT NOT NULL, amount INTEGER NOT NULL, quantity INTEGER NOT NULL)');
        $sqlite->exec('INSERT INTO orders (id, user_id, status, amount, quantity) VALUES'
            . " (1, 10, 'paid', 100, 2), (2, 20, 'paid', 250, 1), (3, 30, 'open', 40, 5)");

        $this->connection = new Connection($sqlite, 'aggregate_test');
    }

    private function select(): Select
    {
        return $this->connection->select('id')->from('orders');
    }

    private function attachLogger(): TestHandler
    {
        $handler = new TestHandler();
        $this->connection->setLogger(new Logger('database', [$handler]), new LoggingOptions(logAllQueries: true));

        return $handler;
    }

    private function loggedSql(TestHandler $handler): string
    {
        $records = $handler->getRecords();
        $this->assertNotSame([], $records, 'the connection logged no query');

        $last = end($records);
        $this->assertNotFalse($last);

        $sql = $last->context['sql'] ?? null;
        $this->assertIsString($sql);

        return $sql;
    }

    /**
     * @return list<array{string, string}>
     */
    public static function aggregateMethods(): array
    {
        return [
            ['sum', 'SUM'],
            ['avg', 'AVG'],
            ['min', 'MIN'],
            ['max', 'MAX'],
        ];
    }

    /**
     * @return list<array{string}>
     */
    public static function aggregateMethodNames(): array
    {
        return [['sum'], ['avg'], ['min'], ['max']];
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateReplacesTheSelectListWithItsOwnCall(string $method, string $function): void
    {
        $handler = $this->attachLogger();

        $this->select()->{$method}('amount');

        $this->assertSame('SELECT ' . $function . '(`amount`) FROM `orders`', $this->loggedSql($handler));
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateKeepsTheConditionsTheBuilderCollected(string $method, string $function): void
    {
        $handler = $this->attachLogger();

        $this->select()->where('status', 'paid')->{$method}('amount');

        $this->assertSame(
            'SELECT ' . $function . '(`amount`) FROM `orders` WHERE `status` = ?',
            $this->loggedSql($handler),
        );
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateDropsTheRowWindow(string $method, string $function): void
    {
        $handler = $this->attachLogger();

        $this->select()->limit(2)->offset(1)->{$method}('amount');

        $this->assertSame('SELECT ' . $function . '(`amount`) FROM `orders`', $this->loggedSql($handler));
    }

    #[DataProvider('aggregateMethodNames')]
    public function testAggregateReadsEveryMatchThroughARowWindow(string $method): void
    {
        // The window would have applied to the single row the aggregate
        // produces, throwing it away rather than narrowing what was read.
        $expected = ['sum' => 390, 'avg' => 130.0, 'min' => 40, 'max' => 250];

        $value = $this->select()->limit(2)->offset(1)->{$method}('amount');

        $this->assertSame($expected[$method], $value);
    }

    public function testSumAddsUpTheColumnOverEveryMatchingRow(): void
    {
        $this->assertSame(350, $this->select()->where('status', 'paid')->sum('amount'));
    }

    public function testAvgDividesTheTotalByHowManyRowsMatched(): void
    {
        $this->assertSame(175.0, $this->select()->where('status', 'paid')->avg('amount'));
    }

    public function testMinReadsTheSmallestValueOfTheColumn(): void
    {
        $this->assertSame(40, $this->select()->min('amount'));
    }

    public function testMaxReadsTheLargestValueOfTheColumn(): void
    {
        $this->assertSame(250, $this->select()->max('amount'));
    }

    #[DataProvider('aggregateMethodNames')]
    public function testAggregateIsNullWhenNothingMatched(string $method): void
    {
        $this->assertNull($this->select()->where('status', 'missing')->{$method}('amount'));
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateQualifiesAColumnWrittenWithItsTable(string $method, string $function): void
    {
        $handler = $this->attachLogger();

        $this->select()->{$method}('orders.amount');

        $this->assertSame(
            'SELECT ' . $function . '(`orders`.`amount`) FROM `orders`',
            $this->loggedSql($handler),
        );
    }

    public function testAggregateReadsTheValueOfACallOverAQualifiedColumn(): void
    {
        // The server keys the row by the call as it was written, so reading by
        // name would miss it. Held with a value rather than only the SQL, so
        // that reading by position is what the test depends on.
        $this->assertSame(390, $this->select()->sum('orders.amount'));
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateAppliesTheTablePrefixToAQualifiedColumn(string $method, string $function): void
    {
        // The call goes through the grammar rather than quoting on its own, so
        // a prefixed pool reaches the same table the rest of the statement
        // does. Quoting directly would name the unprefixed table and the
        // server would answer that the column is unknown.
        $this->connection->setGrammar(new Grammar('app_'));
        $handler = $this->attachLogger();

        try {
            $this->select()->{$method}('orders.amount');
        } catch (QueryException) {
            // The prefixed table does not exist in this fixture; the statement
            // that was sent is what this holds.
        }

        $this->assertSame(
            'SELECT ' . $function . '(`app_orders`.`amount`) FROM `app_orders`',
            $this->loggedSql($handler),
        );
    }

    public function testAggregateRefusesAnIdentifierWithMoreSegmentsThanAColumnCanHave(): void
    {
        // The grammar caps an identifier at schema.table.column. Quoting
        // directly would let a fourth segment through to the server.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn () => $this->select()->sum('a.b.c.d'),
        );

        $this->assertStringContainsString('at most three segments', $thrown->getMessage());
    }

    #[DataProvider('aggregateMethods')]
    public function testAggregateTakesAnExpressionAsWhatToAggregate(string $method, string $function): void
    {
        $handler = $this->attachLogger();

        $this->select()->{$method}(Expression::of('`amount` * `quantity`'));

        $this->assertSame(
            'SELECT ' . $function . '(`amount` * `quantity`) FROM `orders`',
            $this->loggedSql($handler),
        );
    }

    public function testAggregateSendsTheBindingsAnExpressionCarries(): void
    {
        $value = $this->select()->sum(Expression::of('`amount` * ?', [2]));

        $this->assertSame(780, $value);
    }

    public function testAggregateBindsItsExpressionAheadOfTheConditions(): void
    {
        // The select list is written before the WHERE clause, so its values
        // have to be bound in that order for either to mean anything.
        $value = $this->select()->where('status', 'paid')->sum(Expression::of('`amount` * ?', [2]));

        $this->assertSame(700, $value);
    }

    #[DataProvider('aggregateMethodNames')]
    public function testAggregateLeavesTheBuilderAsItWas(string $method): void
    {
        $select = $this->select()->where('status', 'paid')->limit(2);

        $select->{$method}('amount');

        $this->assertSame('SELECT `id` FROM `orders` WHERE `status` = ? LIMIT 2', $select->toSql());
    }

    #[DataProvider('aggregateMethodNames')]
    public function testAggregateIsRefusedWhileTheStatementGroups(string $method): void
    {
        $select = $this->select()->groupBy('user_id');

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->{$method}('amount'));

        $this->assertSame(
            $method . '() reads one value over the rows a statement matches, but this one groups them, so'
            . ' the server would answer with one value per group and the first of those would be read as'
            . ' the whole. Aggregate the rows of get(), or read the groups with execute().',
            $thrown->getMessage(),
        );
    }

    #[DataProvider('aggregateMethodNames')]
    public function testAggregateIsRefusedWhileTheStatementOnlyHasAHavingClause(string $method): void
    {
        $select = $this->select()->having(Expression::of('COUNT(*)'), '>', 1);

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->{$method}('amount'));

        $this->assertStringContainsString('but this one groups them', $thrown->getMessage());
    }

    #[DataProvider('aggregateMethodNames')]
    public function testAggregateIsAllowedWhenTheOnlyHavingPartsAreAnEmptyGroup(string $method): void
    {
        $select = $this->select()
            ->havingOpen()
            ->when(false, static fn (Select $q) => $q->having('user_id', 1))
            ->havingClose();

        $this->assertStringNotContainsString('HAVING', $select->toSql());
        $this->assertNotNull($select->{$method}('amount'));
    }
}
