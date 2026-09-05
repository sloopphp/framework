<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use LogicException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;

final class SelectGroupTest extends TestCase
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

        $this->connection = new Connection($sqlite, 'group_test');
    }

    private function select(): Select
    {
        return $this->connection->select('user_id')->from('orders');
    }

    public function testGroupByWritesTheClause(): void
    {
        $select = $this->select()->groupBy('user_id');

        $this->assertSame('SELECT `user_id` FROM `orders` GROUP BY `user_id`', $select->toSql());
    }

    public function testGroupByTakesSeveralColumnsAtOnce(): void
    {
        $select = $this->select()->groupBy('user_id', 'status');

        $this->assertStringContainsString('GROUP BY `user_id`, `status`', $select->toSql());
    }

    public function testGroupByAddsToWhatEarlierCallsCollected(): void
    {
        $select = $this->select()->groupBy('user_id')->groupBy('status');

        $this->assertStringContainsString('GROUP BY `user_id`, `status`', $select->toSql());
    }

    public function testGroupByTakesAnExpression(): void
    {
        $select = $this->select()->groupBy(Expression::of('YEAR(created_at)'));

        $this->assertStringContainsString('GROUP BY YEAR(created_at)', $select->toSql());
    }

    public function testGroupByWithoutArgumentsChangesNothing(): void
    {
        $select = $this->select()->groupBy();

        $this->assertStringNotContainsString('GROUP BY', $select->toSql());
    }

    public function testHavingWritesTheClauseAfterGroupBy(): void
    {
        $select = $this->select()
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 5);

        $this->assertSame(
            'SELECT `user_id` FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > ?',
            $select->toSql(),
        );
    }

    public function testHavingTakesTwoArgumentsAsAnEqualityComparison(): void
    {
        $select = $this->select()->groupBy('status')->having('status', 'paid');

        $this->assertStringContainsString('HAVING `status` = ?', $select->toSql());
    }

    public function testAndHavingAndOrHavingJoinTheConditionsTheyAdd(): void
    {
        $select = $this->select()
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 5)
            ->andHaving(Expression::of('SUM(total)'), '>', 100)
            ->orHaving('user_id', '<', 10);

        $this->assertStringContainsString(
            'HAVING COUNT(*) > ? AND SUM(total) > ? OR `user_id` < ?',
            $select->toSql(),
        );
    }

    public function testHavingGroupsAreParenthesised(): void
    {
        $select = $this->select()
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 5)
            ->orHavingOpen()
            ->having(Expression::of('SUM(total)'), '>', 100)
            ->andHaving('user_id', '<', 10)
            ->havingClose();

        $this->assertStringContainsString(
            'HAVING COUNT(*) > ? OR (SUM(total) > ? AND `user_id` < ?)',
            $select->toSql(),
        );
    }

    public function testAndHavingOpenAndTheCloseSpellingsReadTheSameAsTheirBaseForm(): void
    {
        $spelled = $this->select()
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 5)
            ->andHavingOpen()
            ->having('user_id', '<', 10)
            ->andHavingClose();

        $plain = $this->select()
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 5)
            ->havingOpen()
            ->having('user_id', '<', 10)
            ->havingClose();

        $this->assertSame($plain->toSql(), $spelled->toSql());
        $this->assertSame($plain->toSql(), $this->select()
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 5)
            ->havingOpen()
            ->having('user_id', '<', 10)
            ->orHavingClose()
            ->toSql());
    }

    public function testHavingRawGoesInAsWrittenAndCarriesItsBindings(): void
    {
        $select = $this->select()
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) BETWEEN ? AND ?', [1, 5]);

        $this->assertStringContainsString('HAVING COUNT(*) BETWEEN ? AND ?', $select->toSql());
        $this->assertSame([1, 5], $select->compile()->bindings);
    }

    public function testOrHavingRawJoinsWithOr(): void
    {
        $select = $this->select()
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 5)
            ->orHavingRaw('SUM(total) > ?', [100]);

        $this->assertStringContainsString('HAVING COUNT(*) > ? OR SUM(total) > ?', $select->toSql());
        $this->assertSame([5, 100], $select->compile()->bindings);
    }

    public function testHavingBindingsFollowTheWhereBindings(): void
    {
        $select = $this->select()
            ->where('status', 'paid')
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 5);

        $this->assertSame(['paid', 5], $select->compile()->bindings);
    }

    public function testHavingWithoutGroupByIsWritten(): void
    {
        $select = $this->select()->having(Expression::of('COUNT(*)'), '>', 5);

        $this->assertStringContainsString('HAVING COUNT(*) > ?', $select->toSql());
    }

    public function testAHavingGroupLeftOpenIsRefusedWhenTheStatementIsCompiled(): void
    {
        $select = $this->select()->groupBy('user_id')->havingOpen()->having('user_id', '<', 10);

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertStringContainsString(
            'A group of HAVING conditions was opened and not closed; call havingClose() 1 more time.',
            $thrown->getMessage(),
        );
    }

    public function testTheCountOfUnclosedHavingGroupsIsNamed(): void
    {
        $select = $this->select()->groupBy('user_id')->havingOpen()->havingOpen();

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->toSql());

        $this->assertStringContainsString(
            'call havingClose() 2 more times.',
            $thrown->getMessage(),
        );
    }

    public function testClosingAHavingGroupThatWasNeverOpenedIsRefusedAtOnce(): void
    {
        $select = $this->select()->groupBy('user_id');

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->havingClose());

        $this->assertStringContainsString(
            'No group of HAVING conditions is open, so there is nothing to close.',
            $thrown->getMessage(),
        );
    }

    public function testAnEmptyHavingGroupWritesNoClause(): void
    {
        $select = $this->select()->groupBy('user_id')->havingOpen()->havingClose();

        $this->assertStringNotContainsString('HAVING', $select->toSql());
    }

    public function testCountIsRefusedWhileTheStatementGroups(): void
    {
        $select = $this->select()->groupBy('user_id');

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->count());

        $this->assertSame(
            'count() counts the rows a statement matches, but this one groups them, so the server would'
            . ' answer with one count per group and the first of those would be read as the whole.'
            . ' Count the rows of get(), or read the groups and count those.',
            $thrown->getMessage(),
        );
    }

    public function testPaginateIsRefusedWhileTheStatementGroups(): void
    {
        $select = $this->select()->groupBy('user_id');

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->paginate(10, 1));

        $this->assertStringContainsString(
            'paginate() counts the rows a statement matches, but this one groups them',
            $thrown->getMessage(),
        );
    }

    public function testCountIsRefusedWhileTheStatementOnlyHasAHavingClause(): void
    {
        $select = $this->select()->having(Expression::of('COUNT(*)'), '>', 5);

        $thrown = $this->assertThrows(LogicException::class, static fn () => $select->count());

        $this->assertStringContainsString(
            'count() counts the rows a statement matches, but this one groups them',
            $thrown->getMessage(),
        );
    }

    public function testGroupByComesAfterTheWhereClauseAndBeforeTheOrderBy(): void
    {
        $select = $this->select()
            ->where('status', 'paid')
            ->groupBy('user_id')
            ->having(Expression::of('COUNT(*)'), '>', 2)
            ->orderBy('user_id', 'DESC')
            ->limit(10);

        $this->assertSame(
            'SELECT `user_id` FROM `orders` WHERE `status` = ? GROUP BY `user_id`'
            . ' HAVING COUNT(*) > ? ORDER BY `user_id` DESC LIMIT 10',
            $select->toSql(),
        );
    }
}
