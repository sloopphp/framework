<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\CompiledSql;
use Sloop\Database\Query\Direction;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Order;
use Sloop\Database\Query\SelectSpec;
use Sloop\Database\Query\WindowExpression;
use Sloop\Tests\Support\ThrowsAssertions;
use stdClass;
use TypeError;

// Expression::over() names the parts of a window call instead of writing them
// as SQL, so a Grammar quotes the columns and reaches them with the table
// prefix. What is pinned here is that seam: which spellings the grammar writes,
// where the bindings land, and that the prefix crosses into the OVER clause --
// none of which holds for the same call written through Expression::of().
final class WindowExpressionTest extends TestCase
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

        $this->connection = new Connection($sqlite, 'window_test');
    }

    public function testTheColumnsOfAWindowCallAreQuotedByTheGrammar(): void
    {
        $compiled = $this->connection
            ->select('id', Expression::over(
                'ROW_NUMBER',
                partitions: ['user_id'],
                orders: ['amount' => 'DESC'],
                alias: 'rn',
            ))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT `id`, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `amount` DESC) AS `rn` FROM `orders`',
            $compiled->sql,
        );
    }

    public function testTheTablePrefixReachesTheColumnsInsideTheOverClause(): void
    {
        // The reason this type exists. An Expression carrying the same SQL is
        // embedded as written, so a qualified column inside it names the table
        // without the prefix and the statement asks for a table that is not
        // there -- while the FROM clause, quoted by the grammar, has it.
        $compiled = new Grammar('p_')->compileSelect(new SelectSpec('orders', [
            'id',
            Expression::over('ROW_NUMBER', partitions: ['orders.user_id'], alias: 'rn'),
        ]));

        $this->assertSame(
            'SELECT `id`, ROW_NUMBER() OVER (PARTITION BY `p_orders`.`user_id`) AS `rn` FROM `p_orders`',
            $compiled->sql,
        );
    }

    public function testAnArgumentIsReadAsAColumnAValueOrAnExpressionByItsType(): void
    {
        $compiled = $this->connection
            ->select(Expression::over(
                'LAG',
                ['amount', 1, Expression::of('COALESCE(?, 0)', [7])],
                partitions: ['user_id'],
            ))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT LAG(`amount`, ?, COALESCE(?, 0)) OVER (PARTITION BY `user_id`) FROM `orders`',
            $compiled->sql,
        );
        $this->assertSame([1, 7], $compiled->bindings);
    }

    public function testAnExpressionArgumentIsNotTheEndOfTheArgumentList(): void
    {
        // An Expression in the middle has to leave the rest of the arguments to
        // follow it. Stopping there instead writes a call short of what was
        // asked for, which the server may well accept as a different call.
        $compiled = $this->connection
            ->select(Expression::over(
                'LEAD',
                [Expression::of('`amount` * ?', [2]), 1],
                orders: ['id'],
                alias: 'next',
            ))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT LEAD(`amount` * ?, ?) OVER (ORDER BY `id` ASC) AS `next` FROM `orders`',
            $compiled->sql,
        );
        $this->assertSame([2, 1], $compiled->bindings);
    }

    public function testAColumnArgumentIsNotTheEndOfTheArgumentListEither(): void
    {
        $compiled = $this->connection
            ->select(Expression::over('NTH_VALUE', ['amount', 2], orders: ['id'], alias: 'second'))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT NTH_VALUE(`amount`, ?) OVER (ORDER BY `id` ASC) AS `second` FROM `orders`',
            $compiled->sql,
        );
        $this->assertSame([2], $compiled->bindings);
    }

    public function testTheValueTypeReindexesTheSortTermsItIsHandedDirectly(): void
    {
        // Expression::over() already passes a list, so the reindexing here only
        // shows through the constructor, which is the way a builder assembling
        // the value step by step would reach it.
        $window = new WindowExpression('ROW_NUMBER', orders: [4 => new Order('score', Direction::Descending)]);

        $compiled = $this->connection->select($window)->from('orders')->compile();

        $this->assertSame('SELECT ROW_NUMBER() OVER (ORDER BY `score` DESC) FROM `orders`', $compiled->sql);
        $this->assertSame([0], array_keys($window->orders));
    }

    public function testTheValueTypeReportsASortTermPositionByOrderNotByKey(): void
    {
        // Expression::over() hands over a list, so the reindexing inside the
        // constructor only shows when it is called directly.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => new WindowExpression('ROW_NUMBER', orders: [3 => new Order('id'), 7 => 'score']),
        );

        $this->assertSame('A sort term of a window is an Order, got string at index 1.', $thrown->getMessage());
    }

    public function testTheBindingsOfAWindowCallComeBeforeTheOnesInWhere(): void
    {
        // The select list is compiled before the where clause, so a value bound
        // inside a window call has to arrive first. Getting this wrong pairs
        // each value with the wrong placeholder rather than failing.
        $compiled = $this->connection
            ->select('id', Expression::over('NTILE', [4], partitions: ['user_id'], alias: 'quartile'))
            ->from('orders')
            ->where('status', '=', 'paid')
            ->compile();

        $this->assertSame(
            'SELECT `id`, NTILE(?) OVER (PARTITION BY `user_id`) AS `quartile` FROM `orders` WHERE `status` = ?',
            $compiled->sql,
        );
        $this->assertSame([4, 'paid'], $compiled->bindings);
    }

    public function testAStarStandsAsTheSoleArgumentOfACall(): void
    {
        $compiled = $this->connection
            ->select(Expression::over('COUNT', ['*'], partitions: ['user_id'], alias: 'per_user'))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT COUNT(*) OVER (PARTITION BY `user_id`) AS `per_user` FROM `orders`',
            $compiled->sql,
        );
    }

    public function testAStarIsRefusedWhereItDoesNotStandForEveryColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->connection
            ->select(Expression::over('COUNT', ['*', 'amount']))
            ->from('orders')
            ->compile();
    }

    public function testACallWithNeitherPartitionNorOrderRunsOverEveryRow(): void
    {
        $compiled = $this->connection
            ->select(Expression::over('SUM', ['amount'], alias: 'total'))
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT SUM(`amount`) OVER () AS `total` FROM `orders`', $compiled->sql);
    }

    public function testASortTermTakesItsDirectionFromAStringKeyAndAscendsWithout(): void
    {
        $compiled = $this->connection
            ->select(Expression::over('ROW_NUMBER', orders: ['amount' => 'desc', 'id']))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT ROW_NUMBER() OVER (ORDER BY `amount` DESC, `id` ASC) FROM `orders`',
            $compiled->sql,
        );
    }

    public function testASortTermWrittenAsSqlCarriesNoAppendedDirection(): void
    {
        // Order states it and orderByRaw() follows it: text that already says
        // how it sorts gets no keyword appended. Appending one writes
        // `... DESC ASC`, which the servers refuse.
        $compiled = $this->connection
            ->select(Expression::over('ROW_NUMBER', orders: [Expression::of('`amount` DESC')]))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT ROW_NUMBER() OVER (ORDER BY `amount` DESC) FROM `orders`',
            $compiled->sql,
        );
    }

    public function testADirectionStandingWhereAColumnIsNamedIsRefused(): void
    {
        // PHP turns a numeric string key into an integer, so ['5' => 'DESC']
        // arrives as a term named DESC rather than as column 5 sorted
        // descending. Sorting by a column called DESC is what that would
        // silently do instead.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', orders: ['5' => 'DESC']),
        );

        $this->assertSame(
            'A sort direction stands where a column is named, got "DESC".'
                . ' Write the direction as the value under the column it applies to,'
                . ' or the whole term as an Expression.',
            $thrown->getMessage(),
        );
    }

    public function testADirectionStandingWhereAColumnIsNamedIsRefusedInAnyCase(): void
    {
        // The direction is matched the way it is elsewhere -- without regard to
        // case -- so the lower-case spelling is caught by the same check rather
        // than sorting by a column called desc.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', orders: ['5' => 'desc']),
        );

        $this->assertStringContainsString('A sort direction stands where a column is named', $thrown->getMessage());
    }

    public function testADirectionWrittenAfterItsColumnInAFlatListIsRefused(): void
    {
        // ['id', 'DESC'] reads as two columns, the second of them named DESC.
        // The direction belongs under the column it applies to, which is what
        // a string key is for.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', orders: ['id', 'DESC']),
        );

        $this->assertStringContainsString('A sort direction stands where a column is named', $thrown->getMessage());
    }

    public function testAColumnNamedAfterADirectionSortsWhenItIsWrittenAsOne(): void
    {
        // The refusals above are about a direction standing where a column
        // goes, not about the word. A column really called desc is reachable
        // through a string key, and everywhere else in the same call -- as an
        // argument, or as a partition -- it needs nothing special.
        $compiled = $this->connection
            ->select(Expression::over('COUNT', ['desc'], partitions: ['desc'], orders: ['desc' => 'ASC']))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT COUNT(`desc`) OVER (PARTITION BY `desc` ORDER BY `desc` ASC) FROM `orders`',
            $compiled->sql,
        );
    }

    public function testAColumnWhoseNameIsADirectionKeywordIsStillReachable(): void
    {
        // The refusal above is about where the value stands, not about the word
        // itself: a string key stays a string, so a column actually called DESC
        // sorts the way it is asked to.
        $compiled = $this->connection
            ->select(Expression::over('ROW_NUMBER', orders: ['DESC' => 'ASC']))
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT ROW_NUMBER() OVER (ORDER BY `DESC` ASC) FROM `orders`', $compiled->sql);
    }

    public function testAWindowCallCanDecideTheOrderOfTheRows(): void
    {
        $rows = $this->connection
            ->select('id')
            ->from('orders')
            ->orderBy(Expression::over('ROW_NUMBER', orders: ['amount' => 'DESC']))
            ->get();

        $this->assertSame([2, 1, 3], array_column($rows, 'id'));
    }

    public function testTheRowNumbersStartOverInEachPartition(): void
    {
        $rows = $this->connection
            ->select('id', Expression::over(
                'ROW_NUMBER',
                partitions: ['user_id'],
                orders: ['amount' => 'DESC'],
                alias: 'rn',
            ))
            ->from('orders')
            ->orderBy('id')
            ->get();

        $this->assertSame([['id' => 1, 'rn' => 2], ['id' => 2, 'rn' => 1], ['id' => 3, 'rn' => 1]], $rows);
    }

    public function testTheFunctionNameIsWrittenInTheSpellingTheGrammarLists(): void
    {
        $compiled = $this->connection
            ->select(Expression::over('row_number', alias: 'rn'))
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT ROW_NUMBER() OVER () AS `rn` FROM `orders`', $compiled->sql);
    }

    public function testACallTheGrammarDoesNotWriteIsRefusedWhereItWasNamed(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): mixed => $this->connection->select(Expression::over('GROUP_CONCAT', ['amount'])),
        );

        $this->assertSame(
            'This grammar writes no window function called GROUP_CONCAT. Add it by overriding windowFunctions().',
            $thrown->getMessage(),
        );
    }

    public function testACallTheGrammarDoesNotWriteIsRefusedInOrderByToo(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            fn (): mixed => $this->connection->select('id')->orderBy(Expression::over('NOT_A_FUNCTION')),
        );

        $this->assertSame(
            'This grammar writes no window function called NOT_A_FUNCTION. Add it by overriding windowFunctions().',
            $thrown->getMessage(),
        );
    }

    public function testASubclassAddsAWindowFunctionTheFrameworkDoesNotList(): void
    {
        $grammar = new class () extends Grammar {
            protected function windowFunctions(): array
            {
                return [...parent::windowFunctions(), 'GROUP_CONCAT'];
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec('orders', [
            Expression::over('GROUP_CONCAT', ['amount'], partitions: ['user_id']),
        ]));

        $this->assertSame(
            'SELECT GROUP_CONCAT(`amount`) OVER (PARTITION BY `user_id`) FROM `orders`',
            $compiled->sql,
        );
    }

    public function testAnAliasIsOneNameSoAQualifiedOneIsRefused(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', alias: 'reporting.rn'),
        );

        $this->assertSame(
            'An alias is one name, so it cannot be qualified, got reporting.rn.',
            $thrown->getMessage(),
        );
    }

    public function testACallNeedsAFunctionName(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('  '),
        );

        $this->assertSame('A window function call needs a function name.', $thrown->getMessage());
    }

    public function testAPartitionNamesAColumnOrIsAnExpression(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', partitions: ['status', 42]),
        );

        $this->assertSame(
            'A partition names a column or is an Expression, got int at index 1.',
            $thrown->getMessage(),
        );
    }

    public function testAnArgumentThatIsNeitherColumnExpressionNorValueIsRefused(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('SUM', ['amount', new stdClass()]),
        );

        $this->assertSame(
            'An argument names a column, is an Expression, or is a value to bind, got stdClass at index 1.',
            $thrown->getMessage(),
        );
    }

    public function testASortDirectionIsAscOrDesc(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', orders: ['amount' => 'sideways']),
        );

        $this->assertSame('A sort direction is ASC or DESC, got "sideways".', $thrown->getMessage());
    }

    public function testASortTermNamesAColumnOrIsAnExpression(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', orders: ['score', 42]),
        );

        $this->assertSame(
            'A sort term names a column or is an Expression, got int at index 1.',
            $thrown->getMessage(),
        );
    }

    public function testAWindowCallIsRefusedWhereOnlyAColumnStands(): void
    {
        // Both servers compute a window function after the rows are grouped, so
        // it has no meaning in GROUP BY, and none in WHERE either -- they say
        // so themselves (MySQL 3593, MariaDB 4015). Leaving those signatures
        // alone is what keeps the call out of them, and the type is what
        // reports it, so this pins that the union was widened where a window
        // stands and nowhere else.
        $this->expectException(TypeError::class);

        // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
        $this->connection->select('id')->from('orders')->groupBy(Expression::over('ROW_NUMBER'));
    }

    public function testAWindowCallIsRefusedInWhereToo(): void
    {
        $this->expectException(TypeError::class);

        // @phpstan-ignore argument.type (the point of the test is that the type refuses it)
        $this->connection->select('id')->from('orders')->where(Expression::over('ROW_NUMBER'), '=', 1);
    }

    public function testTheHeldPartsAreReadableForABuilderThatWrapsThem(): void
    {
        // The parts stay reachable rather than being folded into SQL on the
        // way in, so a builder can read back what it was given and hand the
        // same value to the same grammar.
        $window = Expression::over('SUM', ['amount'], partitions: ['user_id'], alias: 'total');

        $this->assertInstanceOf(WindowExpression::class, $window);
        $this->assertSame('SUM', $window->function);
        $this->assertSame(['amount'], $window->arguments);
        $this->assertSame(['user_id'], $window->partitions);
        $this->assertSame('total', $window->alias);
        $this->assertSame([], $window->orders);
    }

    public function testTheReportedPositionIsTheOrderNotTheKeyItWasGivenUnder(): void
    {
        // The lists are reindexed on the way in, so a caller reading the message
        // counts from the start of what they passed rather than looking for a
        // key that no longer exists.
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', partitions: [5 => 'status', 9 => 42]),
        );

        $this->assertSame(
            'A partition names a column or is an Expression, got int at index 1.',
            $thrown->getMessage(),
        );
    }

    public function testTheReportedArgumentPositionIsAlsoTheOrderNotTheKey(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('SUM', [3 => 'amount', 8 => new stdClass()]),
        );

        $this->assertSame(
            'An argument names a column, is an Expression, or is a value to bind, got stdClass at index 1.',
            $thrown->getMessage(),
        );
    }

    public function testTheReportedSortTermPositionIsAlsoTheOrderNotTheKey(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): mixed => Expression::over('ROW_NUMBER', orders: [2 => 'score', 6 => 42]),
        );

        $this->assertSame(
            'A sort term names a column or is an Expression, got int at index 1.',
            $thrown->getMessage(),
        );
    }

    public function testEveryPartitionIsWrittenNotJustTheFirst(): void
    {
        $compiled = $this->connection
            ->select(Expression::over('COUNT', ['*'], partitions: ['status', 'user_id'], alias: 'n'))
            ->from('orders')
            ->compile();

        $this->assertSame(
            'SELECT COUNT(*) OVER (PARTITION BY `status`, `user_id`) AS `n` FROM `orders`',
            $compiled->sql,
        );
    }

    public function testAnExpressionInThePartitionCarriesItsBindings(): void
    {
        $compiled = $this->connection
            ->select('id', Expression::over(
                'COUNT',
                ['*'],
                partitions: [Expression::of('`amount` > ?', [100])],
                alias: 'n',
            ))
            ->from('orders')
            ->where('status', '=', 'paid')
            ->compile();

        $this->assertSame(
            'SELECT `id`, COUNT(*) OVER (PARTITION BY `amount` > ?) AS `n` FROM `orders` WHERE `status` = ?',
            $compiled->sql,
        );
        $this->assertSame([100, 'paid'], $compiled->bindings);
    }

    public function testAnExpressionInTheSortTermsCarriesItsBindings(): void
    {
        $compiled = $this->connection
            ->select('id', Expression::over(
                'ROW_NUMBER',
                partitions: ['user_id'],
                orders: [Expression::of('`amount` * ?', [2])],
                alias: 'rn',
            ))
            ->from('orders')
            ->where('status', '=', 'paid')
            ->compile();

        // No direction is appended: the SQL of the Expression already says how
        // it sorts, which is the rule Order states and orderByRaw() follows.
        $this->assertSame(
            'SELECT `id`, ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `amount` * ?) AS `rn`'
                . ' FROM `orders` WHERE `status` = ?',
            $compiled->sql,
        );
        $this->assertSame([2, 'paid'], $compiled->bindings);
    }

    public function testTheBindingsOfEveryPartOfACallArriveInWrittenOrder(): void
    {
        // Arguments, then the partitions, then the sort terms -- the order the
        // placeholders appear in. Merging any one of them away, or putting a
        // later group first, pairs the values with the wrong placeholders.
        $compiled = $this->connection
            ->select('id', Expression::over(
                'NTILE',
                [4],
                partitions: [Expression::of('`amount` > ?', [100])],
                orders: [Expression::of('`amount` * ?', [2])],
                alias: 'quartile',
            ))
            ->from('orders')
            ->where('status', '=', 'paid')
            ->compile();

        $this->assertSame(
            'SELECT `id`, NTILE(?) OVER (PARTITION BY `amount` > ? ORDER BY `amount` * ?) AS `quartile`'
                . ' FROM `orders` WHERE `status` = ?',
            $compiled->sql,
        );
        $this->assertSame([4, 100, 2, 'paid'], $compiled->bindings);
    }

    public function testTheFunctionNameIsReadWithoutSurroundingSpace(): void
    {
        $compiled = $this->connection
            ->select(Expression::over('  ROW_NUMBER  ', alias: 'rn'))
            ->from('orders')
            ->compile();

        $this->assertSame('SELECT ROW_NUMBER() OVER () AS `rn` FROM `orders`', $compiled->sql);
    }

    public function testAGrammarCanReplaceHowAWindowCallIsWritten(): void
    {
        // compileWindow() is protected for the same reason every other clause
        // method is: a dialect that spells the call differently replaces one
        // method rather than the whole statement.
        $grammar = new class () extends Grammar {
            protected function compileWindow(WindowExpression $window): CompiledSql
            {
                return new CompiledSql('/* ' . $window->function . ' */');
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec('orders', [
            Expression::over('ROW_NUMBER', partitions: ['user_id']),
        ]));

        $this->assertSame('SELECT /* ROW_NUMBER */ FROM `orders`', $compiled->sql);
    }
}
