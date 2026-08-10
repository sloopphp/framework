<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\CompiledSql;
use Sloop\Database\Query\Condition;
use Sloop\Database\Query\Conjunction;
use Sloop\Database\Query\Direction;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Order;
use Sloop\Database\Query\SelectSpec;

final class GrammarTest extends TestCase
{
    public function testSelectWithoutColumnsSelectsEverything(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(from: 'users'));

        $this->assertSame('SELECT * FROM `users`', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testSelectQuotesEveryColumn(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:    'users',
            columns: ['id', 'name'],
        ));

        $this->assertSame('SELECT `id`, `name` FROM `users`', $compiled->sql);
    }

    public function testSelectKeepsStarUnquoted(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:    'users',
            columns: ['*'],
        ));

        $this->assertSame('SELECT * FROM `users`', $compiled->sql);
    }

    public function testSelectKeepsQualifiedStarUnquoted(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:    'users',
            columns: ['users.*'],
        ));

        $this->assertSame('SELECT `users`.* FROM `users`', $compiled->sql);
    }

    public function testSelectEmbedsExpressionColumnWithItsBindings(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:    'users',
            columns: ['id', Expression::of('GREATEST(?, ?)', [1, 2])],
        ));

        $this->assertSame('SELECT `id`, GREATEST(?, ?) FROM `users`', $compiled->sql);
        $this->assertSame([1, 2], $compiled->bindings);
    }

    public function testPrefixIsAppliedToTheTable(): void
    {
        $compiled = new Grammar('app_')->compileSelect(new SelectSpec(from: 'users'));

        $this->assertSame('SELECT * FROM `app_users`', $compiled->sql);
    }

    public function testPrefixIsAppliedToTheTablePartOfAQualifiedColumn(): void
    {
        $compiled = new Grammar('app_')->compileSelect(new SelectSpec(
            from:    'users',
            columns: ['users.id'],
        ));

        $this->assertSame('SELECT `app_users`.`id` FROM `app_users`', $compiled->sql);
    }

    public function testPrefixIsNotAppliedToAnUnqualifiedColumn(): void
    {
        $compiled = new Grammar('app_')->compileSelect(new SelectSpec(
            from:    'users',
            columns: ['id'],
        ));

        $this->assertSame('SELECT `id` FROM `app_users`', $compiled->sql);
    }

    public function testPrefixIsNotAppliedToAnExpression(): void
    {
        $compiled = new Grammar('app_')->compileSelect(new SelectSpec(
            from:    'users',
            columns: [Expression::increment('users.hits')],
        ));

        $this->assertSame('SELECT `users`.`hits` + 1 FROM `app_users`', $compiled->sql);
    }

    public function testQuoteTablePrefixesTheTableOfASchemaQualifiedName(): void
    {
        $this->assertSame('`reporting`.`app_users`', new Grammar('app_')->quoteTable('reporting.users'));
    }

    public function testQuoteIdentifierPrefixesTheMiddlePartOfASchemaQualifiedColumn(): void
    {
        $this->assertSame('`reporting`.`app_users`.`id`', new Grammar('app_')->quoteIdentifier('reporting.users.id'));
    }

    public function testQuoteIdentifierDoublesBackticks(): void
    {
        $this->assertSame('`we``ird`', new Grammar()->quoteIdentifier('we`ird'));
    }

    public function testQuoteTableRejectsMoreThanTwoSegments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A table name may have at most two segments (schema.table), got reporting.public.users.',
        );

        new Grammar()->quoteTable('reporting.public.users');
    }

    public function testQuoteIdentifierRejectsMoreThanThreeSegments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'An identifier may have at most three segments (schema.table.column), got a.b.c.d.',
        );

        new Grammar()->quoteIdentifier('a.b.c.d');
    }

    #[DataProvider('provideIdentifiersWithAnEmptySegment')]
    public function testQuoteIdentifierRejectsEmptySegments(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Identifier must not contain an empty segment, got ' . $identifier . '.');

        new Grammar()->quoteIdentifier($identifier);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideIdentifiersWithAnEmptySegment(): array
    {
        return [
            'empty string'   => [''],
            'leading dot'    => ['.id'],
            'trailing dot'   => ['users.'],
            'consecutive'    => ['users..id'],
            'only separator' => ['.'],
        ];
    }

    #[DataProvider('provideRejectedPrefixes')]
    public function testConstructorRejectsAPrefixThatIsNotAPlainIdentifier(string $prefix): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Table prefix must contain only alphanumeric and underscore characters, got "' . $prefix . '".',
        );

        new Grammar($prefix);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRejectedPrefixes(): array
    {
        return [
            'dot'       => ['app.'],
            'backtick'  => ['app`'],
            'space'     => ['app '],
            'semicolon' => ['app;'],
        ];
    }

    public function testEmptyPrefixIsAccepted(): void
    {
        $this->assertSame('`users`', new Grammar('')->quoteTable('users'));
    }

    public function testWhereJoinsConditionsWithTheirConjunction(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [
                new Condition('status', '=', 'active'),
                new Condition('role', '=', 'admin', Conjunction::Or),
                new Condition('age', '>=', 18),
            ],
        ));

        $this->assertSame(
            'SELECT * FROM `users` WHERE `status` = ? OR `role` = ? AND `age` >= ?',
            $compiled->sql,
        );
        $this->assertSame(['active', 'admin', 18], $compiled->bindings);
    }

    public function testWhereIgnoresTheConjunctionOfTheFirstCondition(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('status', '=', 'active', Conjunction::Or)],
        ));

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $compiled->sql);
    }

    public function testWhereEmbedsAnExpressionValueInsteadOfBindingIt(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('created_at', '<', Expression::of('NOW()'))],
        ));

        $this->assertSame('SELECT * FROM `users` WHERE `created_at` < NOW()', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testWhereCarriesTheBindingsOfAnExpressionValue(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('score', '>', Expression::of('GREATEST(?, ?)', [10, 20]))],
        ));

        $this->assertSame('SELECT * FROM `users` WHERE `score` > GREATEST(?, ?)', $compiled->sql);
        $this->assertSame([10, 20], $compiled->bindings);
    }

    public function testWhereEmbedsAnExpressionColumn(): void
    {
        $compiled = new Grammar('app_')->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition(Expression::of('LOWER(`name`)'), '=', 'ada')],
        ));

        $this->assertSame('SELECT * FROM `app_users` WHERE LOWER(`name`) = ?', $compiled->sql);
        $this->assertSame(['ada'], $compiled->bindings);
    }

    public function testOrderByDefaultsToAscending(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:   'users',
            orders: [new Order('name')],
        ));

        $this->assertSame('SELECT * FROM `users` ORDER BY `name` ASC', $compiled->sql);
    }

    public function testOrderByEmitsEveryOrderInSequence(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:   'users',
            orders: [
                new Order('created_at', Direction::Descending),
                new Order('users.id', Direction::Ascending),
            ],
        ));

        $this->assertSame(
            'SELECT * FROM `users` ORDER BY `created_at` DESC, `users`.`id` ASC',
            $compiled->sql,
        );
    }

    public function testOrderByEmbedsAnExpressionWithItsBindings(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:   'tasks',
            orders: [new Order(Expression::field('status', ['todo', 'doing', 'done']))],
        ));

        $this->assertSame(
            'SELECT * FROM `tasks` ORDER BY FIELD(`status`, ?, ?, ?) ASC',
            $compiled->sql,
        );
        $this->assertSame(['todo', 'doing', 'done'], $compiled->bindings);
    }

    public function testLimitIsInlinedRatherThanBound(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(from: 'users', limit: 50));

        $this->assertSame('SELECT * FROM `users` LIMIT 50', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testOffsetFollowsTheLimit(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(from: 'users', limit: 10, offset: 20));

        $this->assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 20', $compiled->sql);
    }

    public function testClausesAreEmittedInSqlOrder(): void
    {
        $compiled = new Grammar('app_')->compileSelect(new SelectSpec(
            from:       'users',
            columns:    ['id', Expression::of('IF(?, 1, 0)', ['col'])],
            conditions: [new Condition('status', '=', 'active')],
            orders:     [new Order(Expression::field('role', ['admin', 'editor']), Direction::Descending)],
            limit:      10,
            offset:     20,
        ));

        $this->assertSame(
            'SELECT `id`, IF(?, 1, 0) FROM `app_users` WHERE `status` = ? '
            . 'ORDER BY FIELD(`role`, ?, ?) DESC LIMIT 10 OFFSET 20',
            $compiled->sql,
        );
        $this->assertSame(['col', 'active', 'admin', 'editor'], $compiled->bindings);
    }

    public function testQuoteIdentifierRejectsStarThatIsNotTheLastSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An identifier may only end in *, got *.id.');

        new Grammar()->quoteIdentifier('*.id');
    }

    public function testSelectKeepsTheBindingsOfEveryExpressionColumn(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:    'users',
            columns: [
                Expression::of('GREATEST(?, ?)', [1, 2]),
                Expression::of('LEAST(?, ?)', [3, 4]),
            ],
        ));

        $this->assertSame('SELECT GREATEST(?, ?), LEAST(?, ?) FROM `users`', $compiled->sql);
        $this->assertSame([1, 2, 3, 4], $compiled->bindings);
    }

    public function testOrderByKeepsTheBindingsOfEveryExpression(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:   'tasks',
            orders: [
                new Order(Expression::field('status', ['todo', 'done'])),
                new Order(Expression::field('role', ['admin', 'editor']), Direction::Descending),
            ],
        ));

        $this->assertSame(
            'SELECT * FROM `tasks` ORDER BY FIELD(`status`, ?, ?) ASC, FIELD(`role`, ?, ?) DESC',
            $compiled->sql,
        );
        $this->assertSame(['todo', 'done', 'admin', 'editor'], $compiled->bindings);
    }

    public function testEveryClauseIsOpenToASubclass(): void
    {
        // Phase 5-3 and 5-4 extend the grammar clause by clause, so each
        // clause method has to stay reachable from a subclass.
        $grammar = new class ('app_') extends Grammar {
            protected function compileColumns(array $columns): CompiledSql
            {
                $compiled = parent::compileColumns($columns);

                return new CompiledSql('/*columns*/' . $compiled->sql, $compiled->bindings);
            }

            protected function compileFrom(string $table): string
            {
                return parent::compileFrom($table) . '/*from*/';
            }

            protected function compileWhere(array $conditions): CompiledSql
            {
                $compiled = parent::compileWhere($conditions);

                return new CompiledSql($compiled->sql . '/*where*/', $compiled->bindings);
            }

            protected function compileOrderBy(array $orders): CompiledSql
            {
                $compiled = parent::compileOrderBy($orders);

                return new CompiledSql($compiled->sql . '/*order*/', $compiled->bindings);
            }

            protected function compileLimit(?int $limit, ?int $offset): string
            {
                return parent::compileLimit($limit, $offset) . '/*limit*/';
            }

            protected function compileColumnReference(string|Expression $column): CompiledSql
            {
                $compiled = parent::compileColumnReference($column);

                return new CompiledSql('/*reference*/' . $compiled->sql, $compiled->bindings);
            }

            protected function compileValue(string|int|float|bool|Expression $value): CompiledSql
            {
                $compiled = parent::compileValue($value);

                return new CompiledSql('/*value*/' . $compiled->sql, $compiled->bindings);
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec(
            from:       'users',
            columns:    ['id'],
            conditions: [new Condition('status', '=', 'active')],
            orders:     [new Order('name')],
            limit:      5,
        ));

        $this->assertSame(
            'SELECT /*columns*//*reference*/`id` FROM `app_users`/*from*/'
            . ' WHERE /*reference*/`status` = /*value*/?/*where*/'
            . ' ORDER BY /*reference*/`name` ASC/*order*/'
            . ' LIMIT 5/*limit*/',
            $compiled->sql,
        );
    }

    public function testSubclassCanReplaceASingleClause(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileLimit(?int $limit, ?int $offset): string
            {
                return $limit === null ? '' : ' FETCH FIRST ' . $limit . ' ROWS ONLY';
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec(from: 'users', limit: 5));

        $this->assertSame('SELECT * FROM `users` FETCH FIRST 5 ROWS ONLY', $compiled->sql);
    }
}
