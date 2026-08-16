<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Select;

final class SelectTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)');

        $this->connection = new Connection($sqlite, 'select_test');
    }

    private function seedUsers(): void
    {
        $this->connection->statement(
            'INSERT INTO users (id, name, status) VALUES (1, \'alice\', \'active\'),'
                . ' (2, \'bob\', \'blocked\'), (3, \'carol\', \'active\')',
        );
    }

    public function testSelectWithoutColumnsReadsEveryColumn(): void
    {
        $select = $this->connection->select()->from('users');

        $this->assertSame('SELECT * FROM `users`', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testSelectWritesTheColumnsItWasStartedWith(): void
    {
        $select = $this->connection->select('id', 'name')->from('users');

        $this->assertSame('SELECT `id`, `name` FROM `users`', $select->toSql());
    }

    public function testSelectAcceptsAnExpressionAsAColumn(): void
    {
        $select = $this->connection->select('id', Expression::of('COUNT(*)'))->from('users');

        $this->assertSame('SELECT `id`, COUNT(*) FROM `users`', $select->toSql());
    }

    public function testCompilingWithoutATableIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('call from()');

        $this->connection->select('id')->toSql();
    }

    public function testTwoArgumentWhereComparesForEquality(): void
    {
        $select = $this->connection->select()->from('users')->where('status', 'active');

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
        $this->assertSame(['active'], $select->toBindings());
    }

    public function testTwoArgumentWhereTreatsAnOperatorLikeValueAsAValue(): void
    {
        $select = $this->connection->select()->from('users')->where('name', '=');

        $this->assertSame('SELECT * FROM `users` WHERE `name` = ?', $select->toSql());
        $this->assertSame(['='], $select->toBindings());
    }

    public function testThreeArgumentWhereUsesTheGivenOperator(): void
    {
        $select = $this->connection->select()->from('users')->where('id', '>=', 10);

        $this->assertSame('SELECT * FROM `users` WHERE `id` >= ?', $select->toSql());
        $this->assertSame([10], $select->toBindings());
    }

    public function testWhereAcceptsAnExpressionAsTheValue(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('created_at', '<', Expression::of('NOW()'));

        $this->assertSame('SELECT * FROM `users` WHERE `created_at` < NOW()', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testConditionsAreJoinedInTheOrderTheyWereAdded(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->andWhere('score', '>', 10)
            ->orWhere('name', 'root');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND `score` > ? OR `name` = ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 10, 'root'], $select->toBindings());
    }

    public function testTheFirstConditionCarriesNoConjunction(): void
    {
        $select = $this->connection->select()->from('users')->orWhere('status', 'active');

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testComparingAgainstNullIsRejectedInTheTwoArgumentForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('never true');

        $this->connection->select()->from('users')->where('deleted_at', null);
    }

    public function testComparingAgainstNullIsRejectedInTheThreeArgumentForm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('never true');

        $this->connection->select()->from('users')->where('deleted_at', '=', null);
    }

    public function testAnOperatorThatIsNotAStringIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A comparison operator must be a string, got int.');

        $this->connection->select()->from('users')->where('id', 10, 20);
    }

    public function testAnUnsupportedOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported comparison operator');

        $this->connection->select()->from('users')->where('id', 'BETWEEN', 10);
    }

    public function testOrderBySortsAscendingByDefault(): void
    {
        $select = $this->connection->select()->from('users')->orderBy('name');

        $this->assertSame('SELECT * FROM `users` ORDER BY `name` ASC', $select->toSql());
    }

    /**
     * @param string $direction Direction as written by the caller
     * @param string $expected  Keyword expected in the SQL
     */
    #[DataProvider('sortDirections')]
    public function testOrderByReadsTheDirectionKeywordInAnyCase(string $direction, string $expected): void
    {
        $select = $this->connection->select()->from('users')->orderBy('name', $direction);

        $this->assertSame('SELECT * FROM `users` ORDER BY `name` ' . $expected, $select->toSql());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sortDirections(): array
    {
        return [
            'uppercase descending' => ['DESC', 'DESC'],
            'lowercase descending' => ['desc', 'DESC'],
            'lowercase ascending'  => ['asc', 'ASC'],
            'mixed case'           => ['DeSc', 'DESC'],
        ];
    }

    public function testAnUnknownSortDirectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A sort direction is ASC or DESC, got "sideways".');

        $this->connection->select()->from('users')->orderBy('name', 'sideways');
    }

    public function testOrderByAcceptsAnExpressionAsTheSortKey(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->orderBy(Expression::field('status', ['active', 'pending']));

        $this->assertSame(
            'SELECT * FROM `users` ORDER BY FIELD(`status`, ?, ?) ASC',
            $select->toSql(),
        );
        $this->assertSame(['active', 'pending'], $select->toBindings());
    }

    public function testSortTermsKeepTheOrderTheyWereAddedIn(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->orderBy('status')
            ->orderBy('id', 'DESC');

        $this->assertSame('SELECT * FROM `users` ORDER BY `status` ASC, `id` DESC', $select->toSql());
    }

    public function testLimitAndOffsetBoundTheRows(): void
    {
        $select = $this->connection->select()->from('users')->limit(5)->offset(10);

        $this->assertSame('SELECT * FROM `users` LIMIT 5 OFFSET 10', $select->toSql());
    }

    public function testAZeroLimitAsksForNoRowsRatherThanBeingRejected(): void
    {
        $select = $this->connection->select()->from('users')->limit(0);

        $this->assertSame('SELECT * FROM `users` LIMIT 0', $select->toSql());
    }

    public function testAZeroOffsetSkipsNothingRatherThanBeingRejected(): void
    {
        $select = $this->connection->select()->from('users')->limit(5)->offset(0);

        $this->assertSame('SELECT * FROM `users` LIMIT 5 OFFSET 0', $select->toSql());
    }

    public function testANegativeLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Limit must not be negative, got -1.');

        $this->connection->select()->from('users')->limit(-1);
    }

    public function testANegativeOffsetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Offset must not be negative, got -1.');

        $this->connection->select()->from('users')->offset(-1);
    }

    public function testAnOffsetWithoutALimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('offset needs a limit');

        $this->connection->select()->from('users')->offset(10)->toSql();
    }

    public function testEveryChainingMethodReturnsTheSameBuilder(): void
    {
        $select = $this->connection->select()->from('users');

        $this->assertSame($select, $select->from('users'));
        $this->assertSame($select, $select->where('status', 'active'));
        $this->assertSame($select, $select->andWhere('status', 'active'));
        $this->assertSame($select, $select->orWhere('status', 'active'));
        $this->assertSame($select, $select->orderBy('id'));
        $this->assertSame($select, $select->limit(1));
        $this->assertSame($select, $select->offset(1));
    }

    public function testTheLastTableNamedWins(): void
    {
        $select = $this->connection->select()->from('users')->from('posts');

        $this->assertSame('SELECT * FROM `posts`', $select->toSql());
    }

    public function testTheGrammarComesFromTheConnection(): void
    {
        $this->connection->setGrammar(new Grammar('app_'));

        $select = $this->connection->select('users.id')->from('users')->where('users.status', 'active');

        $this->assertSame(
            'SELECT `app_users`.`id` FROM `app_users` WHERE `app_users`.`status` = ?',
            $select->toSql(),
        );
    }

    public function testConnectionSelectReturnsABuilder(): void
    {
        $this->assertInstanceOf(Select::class, $this->connection->select());
    }

    public function testExecuteReturnsTheRowsTheStatementRead(): void
    {
        $this->seedUsers();

        $rows = $this->connection->select('id', 'name')
            ->from('users')
            ->where('status', 'active')
            ->orderBy('id', 'DESC')
            ->execute();

        $this->assertSame(
            [
                ['id' => 3, 'name' => 'carol'],
                ['id' => 1, 'name' => 'alice'],
            ],
            $rows->asArray(),
        );
    }

    public function testExecuteAppliesTheRowWindow(): void
    {
        $this->seedUsers();

        $rows = $this->connection->select('name')
            ->from('users')
            ->orderBy('id')
            ->limit(1)
            ->offset(1)
            ->execute();

        $this->assertSame([['name' => 'bob']], $rows->asArray());
    }

    public function testRawSqlWritesTheValuesInPlaceOfThePlaceholders(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('name', "O'Brien")
            ->andWhere('score', '>', 10);

        // The number is quoted because that is how the driver receives it:
        // PDOStatement::execute() binds every value of the array as a string.
        $this->assertSame(
            'SELECT * FROM `users` WHERE `name` = \'O\'\'Brien\' AND `score` > \'10\'',
            $select->toRawSql(),
        );
    }

    public function testRawSqlLeavesAPlaceholderWithNoValueAlone(): void
    {
        // The `?` inside the expression's string literal is not a placeholder,
        // so the rendering runs out of values before the SQL runs out of marks.
        $select = $this->connection->select(Expression::of("CONCAT(name, '?')"))
            ->from('users')
            ->where('status', 'active');

        $this->assertSame(
            'SELECT CONCAT(name, \'\'active\'\') FROM `users` WHERE `status` = ?',
            $select->toRawSql(),
        );
    }

    public function testRawSqlCannotTellAQuestionMarkInsideANameFromAPlaceholder(): void
    {
        // Pinned because it is a limitation rather than an accident: the value
        // lands inside the name and the one after it moves up. The statement
        // that runs is unaffected, and the text stops matching it visibly.
        $select = $this->connection->select()
            ->from('users')
            ->where('a?b', 'FIRST')
            ->andWhere('status', 'SECOND');

        $this->assertSame(
            'SELECT * FROM `users` WHERE `a\'FIRST\'b` = \'SECOND\' AND `status` = ?',
            $select->toRawSql(),
        );
    }

    public function testRawSqlOfAStatementWithoutValuesIsTheSqlItself(): void
    {
        $select = $this->connection->select()->from('users');

        $this->assertSame($select->toSql(), $select->toRawSql());
    }

    public function testAListOfConditionsAddsEachOfThem(): void
    {
        $select = $this->connection->select()->from('users')->where([
            ['status', 'active'],
            ['score', '>=', 10],
            ['deleted_at', 'IS', null],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND `score` >= ? AND `deleted_at` IS NULL',
            $select->toSql(),
        );
        $this->assertSame(['active', 10], $select->toBindings());
    }

    public function testAConditionOfTwoComparesForEquality(): void
    {
        $listed     = $this->connection->select()->from('users')->where([['status', 'active']]);
        $spelledOut = $this->connection->select()->from('users')->where('status', 'active');

        $this->assertSame($spelledOut->toSql(), $listed->toSql());
        $this->assertSame($spelledOut->toBindings(), $listed->toBindings());
    }

    public function testAnEmptyListAddsNothing(): void
    {
        $select = $this->connection->select()->from('users')->where([]);

        $this->assertSame('SELECT * FROM `users`', $select->toSql());
    }

    public function testAnEmptyListLeavesTheConditionsAlreadyAdded(): void
    {
        $select = $this->connection->select()->from('users')->where('status', 'active')->where([]);

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testAListJoinsToWhatPrecedesItWithTheConjunctionOfTheCall(): void
    {
        // The OR joins the first of the listed conditions and the rest follow
        // with AND, so the set reads as one alternative. MySQL binds AND tighter
        // than OR, which is what makes the parentheses unnecessary.
        $select = $this->connection->select()
            ->from('users')
            ->where('id', 1)
            ->orWhere([['status', 'active'], ['score', '>=', 10]]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` = ? OR `status` = ? AND `score` >= ?',
            $select->toSql(),
        );
    }

    public function testAListAcceptsAnExpressionAsAColumnAndAsAValue(): void
    {
        $select = $this->connection->select()->from('users')->where([
            [Expression::of('LOWER(`name`)'), 'alice'],
            ['created_at', '<', Expression::of('NOW()')],
        ]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE LOWER(`name`) = ? AND `created_at` < NOW()',
            $select->toSql(),
        );
        $this->assertSame(['alice'], $select->toBindings());
    }

    public function testACallableOpensAGroupAroundWhatItAdds(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->where(static fn (Select $query): Select => $query->where('id', 1)->orWhere('id', 2));

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`id` = ? OR `id` = ?)',
            $select->toSql(),
        );
    }

    public function testACallableGivenToOrWhereJoinsItsGroupWithOr(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->orWhere(static fn (Select $query): Select => $query->where('id', 1)->andWhere('score', '>', 10));

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? OR (`id` = ? AND `score` > ?)',
            $select->toSql(),
        );
    }

    public function testACallableWritesTheSameStatementAsTheOpenAndCloseCalls(): void
    {
        $withCallable = $this->connection->select()
            ->from('users')
            ->where(static fn (Select $query): Select => $query->where('id', 1)->orWhere('id', 2));

        $withOpenClose = $this->connection->select()
            ->from('users')
            ->whereOpen()->where('id', 1)->orWhere('id', 2)->whereClose();

        $this->assertSame($withOpenClose->toSql(), $withCallable->toSql());
    }

    public function testACallableThatAddsNothingLeavesNoEmptyParentheses(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->where(static fn (Select $query): Select => $query);

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testAGroupIsClosedEvenWhereTheClosureFails(): void
    {
        $select = $this->connection->select()->from('users')->where('status', 'active');

        try {
            $select->where(static function (Select $query): void {
                $query->where('id', 1);

                throw new RuntimeException('from inside the group');
            });
            $this->fail('The failure inside the closure should reach the caller.');
        } catch (RuntimeException $failure) {
            $this->assertSame('from inside the group', $failure->getMessage());
        }

        // 括弧が閉じているので、そのまま組み立てられる。閉じていなければ
        // compile() が LogicException になる。
        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`id` = ?)',
            $select->toSql(),
        );
    }

    public function testAListAndACallableCombine(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where([['status', 'active'], ['score', '>=', 10]])
            ->where(static fn (Select $query): Select => $query->where('role', 'admin')->orWhere('role', 'editor'));

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND `score` >= ? AND (`role` = ? OR `role` = ?)',
            $select->toSql(),
        );
    }

    public function testAColumnOnItsOwnIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A comparison needs something to compare against; where() was given only a column.'
            . ' Pass a value, or an operator and a value.',
        );

        $this->connection->select()->from('users')->where('status');
    }

    /**
     * @param array<int|string, mixed> $conditions
     */
    #[DataProvider('provideMalformedConditionLists')]
    public function testAMalformedListIsRejected(array $conditions, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->connection->select()->from('users')->where($conditions);
    }

    /**
     * @return array<string, array{array<int|string, mixed>, string}>
     */
    public static function provideMalformedConditionLists(): array
    {
        return [
            'condition is not a list' => [
                ['junk'],
                'Each condition must be a list of two or three, got string at index 0.',
            ],
            'condition of one' => [
                [['status']],
                'got 1 at index 0.',
            ],
            'condition of four' => [
                [['status', '=', 'active', 'or']],
                'got 4 at index 0.',
            ],
            'the reported index is the position' => [
                [['status', 'active'], ['score']],
                'got 1 at index 1.',
            ],
            'column is not a column' => [
                [[10, '=', 1]],
                'so it must be a string or an Expression, got int at index 0.',
            ],
            'value cannot be compared' => [
                [['status', ['nested']]],
                'compares against a scalar, null or an Expression, got array at index 0.',
            ],
        ];
    }

    public function testTheReportedPositionIsThePositionInTheListNotTheOriginalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('got 1 at index 0.');

        $this->connection->select()->from('users')->where([7 => ['status']]);
    }

    public function testAConditionIsReadByPositionRatherThanByKey(): void
    {
        $select = $this->connection->select()->from('users')->where([
            ['column' => 'status', 'value' => 'active'],
        ]);

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
        $this->assertSame(['active'], $select->toBindings());
    }

    public function testAListRejectsNullTheSameWayTheArgumentsDo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Write IS or IS NOT to test for NULL.');

        $this->connection->select()->from('users')->where([['deleted_at', '=', null]]);
    }

    public function testTestingForNullWritesTheKeywordRatherThanAPlaceholder(): void
    {
        $select = $this->connection->select()->from('users')->where('deleted_at', 'IS', null);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testTestingForNotNullWritesTheKeywordRatherThanAPlaceholder(): void
    {
        $select = $this->connection->select()->from('users')->where('deleted_at', 'IS NOT', null);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NOT NULL', $select->toSql());
        $this->assertSame([], $select->toBindings());
    }

    public function testWhereNullIsTheSameStatementAsTheIsOperator(): void
    {
        $spelledOut = $this->connection->select()->from('users')->where('deleted_at', 'IS', null);
        $named      = $this->connection->select()->from('users')->whereNull('deleted_at');

        $this->assertSame($spelledOut->toSql(), $named->toSql());
    }

    public function testWhereNotNullIsTheSameStatementAsTheIsNotOperator(): void
    {
        $spelledOut = $this->connection->select()->from('users')->where('deleted_at', 'IS NOT', null);
        $named      = $this->connection->select()->from('users')->whereNotNull('deleted_at');

        $this->assertSame($spelledOut->toSql(), $named->toSql());
    }

    public function testTheNullSafeEqualBindsNullRatherThanWritingTheKeyword(): void
    {
        // <=> compares values and answers true for two nulls, so null reaches
        // it as a bound value like any other rather than as a keyword.
        $select = $this->connection->select()->from('users')->where('deleted_at', '<=>', null);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` <=> ?', $select->toSql());
        $this->assertSame([null], $select->toBindings());
    }

    public function testTheIsOperatorRefusesAValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Use = to compare against a value.');

        $this->connection->select()->from('users')->where('id', 'IS', 10);
    }

    public function testRejectingNullPointsAtTheOperatorThatTestsForIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Write IS or IS NOT to test for NULL.');

        $this->connection->select()->from('users')->where('deleted_at', '=', null);
    }

    public function testWhereInWritesOnePlaceholderPerValue(): void
    {
        $select = $this->connection->select()->from('users')->whereIn('status', ['active', 'pending']);

        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?, ?)', $select->toSql());
        $this->assertSame(['active', 'pending'], $select->toBindings());
    }

    public function testWhereNotInNegatesTheMembershipTest(): void
    {
        $select = $this->connection->select()->from('users')->whereNotIn('status', ['blocked']);

        $this->assertSame('SELECT * FROM `users` WHERE `status` NOT IN (?)', $select->toSql());
        $this->assertSame(['blocked'], $select->toBindings());
    }

    public function testNullAmongTheValuesOfAMembershipTestIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('makes NOT IN match no rows at all');

        $this->connection->select()->from('users')->whereNotIn('status', ['blocked', null]);
    }

    public function testWhereBetweenWritesBothBounds(): void
    {
        $select = $this->connection->select()->from('users')->whereBetween('id', 10, 20);

        $this->assertSame('SELECT * FROM `users` WHERE `id` BETWEEN ? AND ?', $select->toSql());
        $this->assertSame([10, 20], $select->toBindings());
    }

    public function testAGroupOfConditionsIsParenthesised(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->whereOpen()
                ->where('id', '<', 10)
                ->orWhere('name', 'alice')
            ->whereClose();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND (`id` < ? OR `name` = ?)',
            $select->toSql(),
        );
        $this->assertSame(['active', 10, 'alice'], $select->toBindings());
    }

    public function testAGroupCarriesTheConjunctionItWasOpenedWith(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->orWhereOpen()
                ->where('id', '<', 10)
            ->orWhereClose();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? OR (`id` < ?)',
            $select->toSql(),
        );
    }

    public function testAGroupOpeningTheClauseCarriesNoConjunction(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->whereOpen()
                ->where('id', '<', 10)
            ->whereClose()
            ->andWhere('status', 'active');

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`id` < ?) AND `status` = ?',
            $select->toSql(),
        );
    }

    public function testGroupsNestOneInsideAnother(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->whereOpen()
                ->where('id', '<', 10)
                ->orWhereOpen()
                    ->where('name', 'alice')
                    ->andWhere('status', 'active')
                ->orWhereClose()
            ->whereClose();

        $this->assertSame(
            'SELECT * FROM `users` WHERE (`id` < ? OR (`name` = ? AND `status` = ?))',
            $select->toSql(),
        );
    }

    public function testAndWhereOpenReadsTheSameAsWhereOpen(): void
    {
        $plain   = $this->connection->select()->from('users')->where('id', 1)->whereOpen()->where('id', 2)->whereClose();
        $spelled = $this->connection->select()->from('users')->where('id', 1)->andWhereOpen()->where('id', 2)->andWhereClose();

        $this->assertSame($plain->toSql(), $spelled->toSql());
    }

    public function testLeavingAGroupOpenIsRejectedWhenTheStatementIsCompiled(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('call whereClose() 1 more time.');

        $this->connection->select()->from('users')->whereOpen()->where('id', 1)->toSql();
    }

    public function testTheNumberOfGroupsLeftOpenIsReported(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('call whereClose() 2 more times.');

        $this->connection->select()->from('users')->whereOpen()->whereOpen()->where('id', 1)->toSql();
    }

    public function testClosingAGroupThatWasNeverOpenedIsRejectedWhereItIsWritten(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('nothing to close');

        $this->connection->select()->from('users')->whereClose();
    }

    public function testAGroupLeftEmptyIsDroppedRatherThanWrittenAsEmptyParentheses(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->whereOpen()
            ->whereClose();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testAStatementWhoseOnlyGroupIsEmptyHasNoWhereClause(): void
    {
        $select = $this->connection->select()->from('users')->whereOpen()->whereClose();

        $this->assertSame('SELECT * FROM `users`', $select->toSql());
    }

    public function testNestedEmptyGroupsAreDroppedTogether(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->whereOpen()
                ->whereOpen()
                ->whereClose()
            ->whereClose();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testWhenAppliesTheCallbackWhileTheConditionHolds(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(true, static fn (Select $query): Select => $query->where('status', 'active'));

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
    }

    public function testWhenLeavesTheStatementAloneWhileTheConditionDoesNotHold(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(false, static fn (Select $query): Select => $query->where('status', 'active'));

        $this->assertSame('SELECT * FROM `users`', $select->toSql());
    }

    public function testWhenFallsBackToTheSecondCallback(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(
                false,
                static fn (Select $query): Select => $query->where('status', 'active'),
                static fn (Select $query): Select => $query->where('status', 'blocked'),
            );

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
        $this->assertSame(['blocked'], $select->toBindings());
    }

    public function testWhenLeavesTheSecondCallbackAloneWhileTheConditionHolds(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->when(
                true,
                static fn (Select $query): Select => $query->where('status', 'active'),
                static fn (Select $query): Select => $query->where('status', 'blocked'),
            );

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $select->toSql());
        $this->assertSame(['active'], $select->toBindings());
    }

    public function testWhereRawWritesTheConditionAsGiven(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->whereRaw('LENGTH(name) > ?', [3]);

        $this->assertSame('SELECT * FROM `users` WHERE LENGTH(name) > ?', $select->toSql());
        $this->assertSame([3], $select->toBindings());
    }

    public function testWhereRawJoinsToWhatPrecedesIt(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->whereRaw('LENGTH(name) > ?', [3]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? AND LENGTH(name) > ?',
            $select->toSql(),
        );
        $this->assertSame(['active', 3], $select->toBindings());
    }

    public function testOrWhereRawJoinsWithOr(): void
    {
        $select = $this->connection->select()
            ->from('users')
            ->where('status', 'active')
            ->orWhereRaw('LENGTH(name) > ?', [3]);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? OR LENGTH(name) > ?',
            $select->toSql(),
        );
    }

    public function testSelectRawAddsAColumnWrittenAsSql(): void
    {
        $select = $this->connection->select('id')->from('users')->selectRaw('LENGTH(name) AS len');

        $this->assertSame('SELECT `id`, LENGTH(name) AS len FROM `users`', $select->toSql());
    }

    public function testOrderByRawWritesTheTermWithoutADirection(): void
    {
        $select = $this->connection->select()->from('users')->orderByRaw('FIELD(status, ?, ?)', ['active', 'blocked']);

        $this->assertSame('SELECT * FROM `users` ORDER BY FIELD(status, ?, ?)', $select->toSql());
        $this->assertSame(['active', 'blocked'], $select->toBindings());
    }

    public function testBindingsFollowThePlaceholdersAcrossEveryClause(): void
    {
        $select = $this->connection->select('id')
            ->from('users')
            ->selectRaw('IF(status = ?, 1, 0) AS flag', ['active'])
            ->where('id', '>', 1)
            ->whereIn('status', ['active', 'pending'])
            ->orderByRaw('FIELD(name, ?)', ['alice']);

        $this->assertSame(['active', 1, 'active', 'pending', 'alice'], $select->toBindings());
    }
}
