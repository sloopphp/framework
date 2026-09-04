<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Dialect;
use Sloop\Database\Query\Assignment;
use Sloop\Database\Query\BetweenCondition;
use Sloop\Database\Query\CompiledSql;
use Sloop\Database\Query\Condition;
use Sloop\Database\Query\Conjunction;
use Sloop\Database\Query\DeleteSpec;
use Sloop\Database\Query\Direction;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\InCondition;
use Sloop\Database\Query\InsertSpec;
use Sloop\Database\Query\Operand;
use Sloop\Database\Query\Order;
use Sloop\Database\Query\RowLock;
use Sloop\Database\Query\SelectSpec;
use Sloop\Database\Query\UpdateSpec;
use Sloop\Database\Query\WherePart;

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

    public function testInsertNamesTheColumnsOnceAndFollowsWithATuplePerRow(): void
    {
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['name', 'score'],
            rows:    [['alice', 10], ['bob', 20]],
        ));

        $this->assertSame('INSERT INTO `users` (`name`, `score`) VALUES (?, ?), (?, ?)', $compiled->sql);
        $this->assertSame(['alice', 10, 'bob', 20], $compiled->bindings);
    }

    public function testInsertWritesIgnoreWhenTheSpecAsksForIt(): void
    {
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['name'],
            rows:    [['alice']],
            ignore:  true,
        ));

        $this->assertSame('INSERT IGNORE INTO `users` (`name`) VALUES (?)', $compiled->sql);
    }

    public function testInsertWithNothingToOverwriteWritesNoUpdateClause(): void
    {
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['name'],
            rows:    [['alice']],
        ));

        $this->assertSame('INSERT INTO `users` (`name`) VALUES (?)', $compiled->sql);
    }

    public function testInsertNamesTheIncomingValueThroughValuesWhenThereIsNoRowAlias(): void
    {
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['email', 'name', 'score'],
            rows:    [['a@example.com', 'alice', 10]],
            upsert:  ['name', 'score'],
        ));

        $this->assertSame(
            'INSERT INTO `users` (`email`, `name`, `score`) VALUES (?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `score` = VALUES(`score`)',
            $compiled->sql,
        );
        $this->assertSame(['a@example.com', 'alice', 10], $compiled->bindings);
    }

    public function testInsertNamesTheIncomingValueThroughTheRowAliasWhenTheServerReadsOne(): void
    {
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:    'users',
            columns:  ['email', 'score'],
            rows:     [['a@example.com', 10]],
            upsert:   ['score'],
            rowAlias: true,
        ));

        $this->assertSame(
            'INSERT INTO `users` (`email`, `score`) VALUES (?, ?)'
                . ' AS `sloop_upsert` ON DUPLICATE KEY UPDATE `score` = `sloop_upsert`.`score`',
            $compiled->sql,
        );
        $this->assertSame(['a@example.com', 10], $compiled->bindings);
    }

    public function testUpsertPrefixesNeitherTheAliasNorTheColumns(): void
    {
        $compiled = new Grammar('wp_')->compileInsert(new InsertSpec(
            table:    'users',
            columns:  ['email', 'score'],
            rows:     [['a@example.com', 10]],
            upsert:   ['score'],
            rowAlias: true,
        ));

        $this->assertSame(
            'INSERT INTO `wp_users` (`email`, `score`) VALUES (?, ?)'
                . ' AS `sloop_upsert` ON DUPLICATE KEY UPDATE `score` = `sloop_upsert`.`score`',
            $compiled->sql,
        );
    }

    public function testSubclassCanReplaceTheUpdateClauseOfAnUpsert(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileUpsert(array $columns, ?string $alias): CompiledSql
            {
                return new CompiledSql(' /* ' . implode('|', $columns) . ' */');
            }
        };

        $compiled = $grammar->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['email', 'score'],
            rows:    [['a@example.com', 10]],
            upsert:  ['score'],
        ));

        $this->assertSame(
            'INSERT INTO `users` (`email`, `score`) VALUES (?, ?) /* score */',
            $compiled->sql,
        );
    }

    public function testSubclassCanChooseTheRowAlias(): void
    {
        $grammar = new class () extends Grammar {
            protected function rowAliasFor(string $quotedTable): string
            {
                return '`incoming`';
            }
        };

        $compiled = $grammar->compileInsert(new InsertSpec(
            table:    'users',
            columns:  ['email', 'score'],
            rows:     [['a@example.com', 10]],
            upsert:   ['score'],
            rowAlias: true,
        ));

        $this->assertSame(
            'INSERT INTO `users` (`email`, `score`) VALUES (?, ?)'
                . ' AS `incoming` ON DUPLICATE KEY UPDATE `score` = `incoming`.`score`',
            $compiled->sql,
        );
    }

    public function testTheRowAliasStepsAsideFromATableOfItsOwnName(): void
    {
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:    'sloop_upsert',
            columns:  ['email', 'score'],
            rows:     [['a@example.com', 10]],
            upsert:   ['score'],
            rowAlias: true,
        ));

        $this->assertSame(
            'INSERT INTO `sloop_upsert` (`email`, `score`) VALUES (?, ?)'
                . ' AS `sloop_upsert_row` ON DUPLICATE KEY UPDATE `score` = `sloop_upsert_row`.`score`',
            $compiled->sql,
        );
    }

    public function testTheRowAliasStepsAsideFromATableThePrefixNamedAfterIt(): void
    {
        // The alias is compared against the name the server sees, so a caller
        // who never writes the alias can still land on it through the prefix.
        $compiled = new Grammar('sloop_')->compileInsert(new InsertSpec(
            table:    'upsert',
            columns:  ['email', 'score'],
            rows:     [['a@example.com', 10]],
            upsert:   ['score'],
            rowAlias: true,
        ));

        $this->assertSame(
            'INSERT INTO `sloop_upsert` (`email`, `score`) VALUES (?, ?)'
                . ' AS `sloop_upsert_row` ON DUPLICATE KEY UPDATE `score` = `sloop_upsert_row`.`score`',
            $compiled->sql,
        );
    }

    public function testTheRowAliasStepsAsideFromATableThatDiffersOnlyInCase(): void
    {
        // Whether the two are the same name is the server's to decide: MySQL
        // folds table names when lower_case_table_names is not 0, and refuses
        // this pairing with 1066 (measured on 8.0.46 with the setting at 1).
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:    'Sloop_Upsert',
            columns:  ['score'],
            rows:     [[10]],
            upsert:   ['score'],
            rowAlias: true,
        ));

        $this->assertSame(
            'INSERT INTO `Sloop_Upsert` (`score`) VALUES (?)'
                . ' AS `sloop_upsert_row` ON DUPLICATE KEY UPDATE `score` = `sloop_upsert_row`.`score`',
            $compiled->sql,
        );
    }

    public function testOnlyTheTableOfTheStatementPushesTheRowAliasAside(): void
    {
        // A schema of the alias's name shares no namespace with it.
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:    'sloop_upsert.users',
            columns:  ['score'],
            rows:     [[10]],
            upsert:   ['score'],
            rowAlias: true,
        ));

        $this->assertSame(
            'INSERT INTO `sloop_upsert`.`users` (`score`) VALUES (?)'
                . ' AS `sloop_upsert` ON DUPLICATE KEY UPDATE `score` = `sloop_upsert`.`score`',
            $compiled->sql,
        );
    }

    /**
     * @param Dialect $dialect  Server the statement would go to
     * @param string  $version  What that server answers to SELECT VERSION()
     * @param bool    $expected Whether the alias form is expected to be written
     */
    #[DataProvider('rowAliasSupport')]
    public function testTheRowAliasIsWrittenOnlyForAMysqlThatReadsIt(
        Dialect $dialect,
        string $version,
        bool $expected,
    ): void {
        $this->assertSame($expected, new Grammar()->supportsRowAlias($dialect, $version));
    }

    /**
     * @return array<string, array{Dialect, string, bool}>
     */
    public static function rowAliasSupport(): array
    {
        return [
            'the MySQL the alias arrived in'      => [Dialect::MySQL, '8.0.19', true],
            'a later MySQL'                       => [Dialect::MySQL, '8.0.46', true],
            'the MySQL just before it'            => [Dialect::MySQL, '8.0.18', false],
            'a MySQL 5.7'                         => [Dialect::MySQL, '5.7.44', false],
            'a MySQL 9'                           => [Dialect::MySQL, '9.1.0', true],
            'a build suffix after the version'    => [Dialect::MySQL, '8.0.46-log', true],
            'MariaDB, whichever version'          => [Dialect::MariaDB, '10.11.18-MariaDB', false],
            'a MariaDB numbered above the MySQL'  => [Dialect::MariaDB, '11.4.2-MariaDB', false],
            'a version string with no numbers'    => [Dialect::MySQL, 'who knows', false],
            'a version too short to compare'      => [Dialect::MySQL, '8.0', false],
            'an empty version'                    => [Dialect::MySQL, '', false],
        ];
    }

    public function testInsertEmbedsAnExpressionWithItsBindings(): void
    {
        $compiled = new Grammar()->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['name', 'score'],
            rows:    [['alice', Expression::of('ABS(?)', [-5])]],
        ));

        $this->assertSame('INSERT INTO `users` (`name`, `score`) VALUES (?, ABS(?))', $compiled->sql);
        $this->assertSame(['alice', -5], $compiled->bindings);
    }

    public function testInsertPrefixesTheTableButNotTheColumns(): void
    {
        $compiled = new Grammar('app_')->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['name'],
            rows:    [['alice']],
        ));

        $this->assertSame('INSERT INTO `app_users` (`name`) VALUES (?)', $compiled->sql);
    }

    public function testSubclassCanReplaceTheInsertColumnList(): void
    {
        // The column list carries bindings so a dialect writing a placeholder
        // there has somewhere to put the value; compileInsert keeps them ahead
        // of the ones the rows need.
        $grammar = new class () extends Grammar {
            protected function compileInsertColumns(array $columns): CompiledSql
            {
                return new CompiledSql(' (?)', [\count($columns)]);
            }
        };

        $compiled = $grammar->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['name'],
            rows:    [['alice']],
        ));

        $this->assertSame('INSERT INTO `users` (?) VALUES (?)', $compiled->sql);
        $this->assertSame([1, 'alice'], $compiled->bindings);
    }

    public function testSubclassCanReplaceTheInsertTuples(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileInsertRows(array $rows): CompiledSql
            {
                return new CompiledSql('(' . \count($rows) . ' rows)');
            }
        };

        $compiled = $grammar->compileInsert(new InsertSpec(
            table:   'users',
            columns: ['name'],
            rows:    [['alice'], ['bob']],
        ));

        $this->assertSame('INSERT INTO `users` (`name`) VALUES (2 rows)', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testDeleteWithoutConditionsAddressesTheWholeTable(): void
    {
        $compiled = new Grammar()->compileDelete(new DeleteSpec(from: 'users'));

        $this->assertSame('DELETE FROM `users`', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testDeleteWritesTheClausesInTheOrderMysqlTakesThem(): void
    {
        $compiled = new Grammar()->compileDelete(new DeleteSpec(
            from:       'users',
            conditions: [new Condition('status', '=', 'blocked')],
            orders:     [new Order('id', Direction::Descending)],
            limit:      2,
        ));

        $this->assertSame('DELETE FROM `users` WHERE `status` = ? ORDER BY `id` DESC LIMIT 2', $compiled->sql);
        $this->assertSame(['blocked'], $compiled->bindings);
    }

    public function testDeleteWritesNoOffsetAlongsideTheLimit(): void
    {
        $compiled = new Grammar()->compileDelete(new DeleteSpec(from: 'users', limit: 1));

        $this->assertSame('DELETE FROM `users` LIMIT 1', $compiled->sql);
    }

    public function testDeletePrefixesTheTable(): void
    {
        $compiled = new Grammar('app_')->compileDelete(new DeleteSpec(from: 'users'));

        $this->assertSame('DELETE FROM `app_users`', $compiled->sql);
    }

    public function testUpdateWritesTheClausesInTheOrderMysqlTakesThem(): void
    {
        $compiled = new Grammar()->compileUpdate(new UpdateSpec(
            table:       'users',
            assignments: [new Assignment('status', 'active'), new Assignment('score', 0)],
            conditions:  [new Condition('status', '=', 'blocked')],
            orders:      [new Order('id', Direction::Descending)],
            limit:       2,
        ));

        $this->assertSame(
            'UPDATE `users` SET `status` = ?, `score` = ? WHERE `status` = ? ORDER BY `id` DESC LIMIT 2',
            $compiled->sql,
        );
        $this->assertSame(['active', 0, 'blocked'], $compiled->bindings);
    }

    public function testUpdateWritesAnExpressionAssignmentWithItsBindings(): void
    {
        $compiled = new Grammar()->compileUpdate(new UpdateSpec(
            table:       'users',
            assignments: [new Assignment('score', Expression::of('score + ?', [5]))],
        ));

        $this->assertSame('UPDATE `users` SET `score` = score + ?', $compiled->sql);
        $this->assertSame([5], $compiled->bindings);
    }

    public function testUpdateWritesNoOffsetAlongsideTheLimit(): void
    {
        $compiled = new Grammar()->compileUpdate(new UpdateSpec(
            table:       'users',
            assignments: [new Assignment('score', 0)],
            limit:       1,
        ));

        $this->assertSame('UPDATE `users` SET `score` = ? LIMIT 1', $compiled->sql);
    }

    public function testUpdatePrefixesTheTable(): void
    {
        $compiled = new Grammar('app_')->compileUpdate(new UpdateSpec(
            table:       'users',
            assignments: [new Assignment('score', 0)],
        ));

        $this->assertSame('UPDATE `app_users` SET `score` = ?', $compiled->sql);
    }

    public function testSubclassCanReplaceTheSetClause(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileSet(array $assignments): CompiledSql
            {
                return new CompiledSql(' SET (' . \count($assignments) . ' columns)');
            }
        };

        $compiled = $grammar->compileUpdate(new UpdateSpec(
            table:       'users',
            assignments: [new Assignment('score', 0)],
        ));

        $this->assertSame('UPDATE `users` SET (1 columns)', $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    public function testUpdateWithNothingToAssignWritesNoSetClause(): void
    {
        $compiled = new Grammar()->compileUpdate(new UpdateSpec(table: 'users'));

        $this->assertSame('UPDATE `users`', $compiled->sql, 'the Update builder refuses this before it is compiled');
        $this->assertSame([], $compiled->bindings);
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
        $this->expectExceptionMessageIsOrContains(
            'A table name may have at most two segments (schema.table), got reporting.public.users.',
        );

        new Grammar()->quoteTable('reporting.public.users');
    }

    public function testQuoteIdentifierRejectsMoreThanThreeSegments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'An identifier may have at most three segments (schema.table.column), got a.b.c.d.',
        );

        new Grammar()->quoteIdentifier('a.b.c.d');
    }

    #[DataProvider('provideIdentifiersWithAnEmptySegment')]
    public function testQuoteIdentifierRejectsEmptySegments(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Identifier must not contain an empty segment, got ' . $identifier . '.');

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
        $this->expectExceptionMessageIsOrContains(
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
        $this->expectExceptionMessageIsOrContains('Only the last part of a column reference may be *, got *.id.');

        new Grammar()->quoteIdentifier('*.id', allowEveryColumn: true);
    }

    public function testQuoteIdentifierRejectsEveryColumnUnlessTheCallerAsksForIt(): void
    {
        // The public default is the strict one: only a caller that knows it is
        // building a list of columns may pass *.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '* names every column, so it only stands where a list of columns does, got users.*.',
        );

        new Grammar()->quoteIdentifier('users.*');
    }

    public function testQuoteIdentifierAllowsEveryColumnWhenTheCallerAsksForIt(): void
    {
        $this->assertSame(
            '`app_users`.*',
            new Grammar('app_')->quoteIdentifier('users.*', allowEveryColumn: true),
        );
    }

    public function testQuoteTableRejectsStar(): void
    {
        // * names every column, so it can never name a table. Letting it
        // through produced `FROM *`, which only fails once MySQL parses it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '* names every column, so it only stands where a list of columns does, got *.',
        );

        new Grammar()->quoteTable('*');
    }

    public function testWhereKeepsColumnBindingsBeforeValueBindings(): void
    {
        // Both sides of the comparison carry bindings, so a merge in the wrong
        // order swaps values that are still the right count and type.
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'tasks',
            conditions: [new Condition(
                Expression::field('status', ['todo', 'doing']),
                '=',
                Expression::of('ELT(?, 1, 2)', [7]),
            )],
        ));

        $this->assertSame(
            'SELECT * FROM `tasks` WHERE FIELD(`status`, ?, ?) = ELT(?, 1, 2)',
            $compiled->sql,
        );
        $this->assertSame(['todo', 'doing', 7], $compiled->bindings);
    }

    public function testConstructorRejectsAPrefixEndingInANewline(): void
    {
        // PCRE's $ also matches before a trailing newline, so an anchor of ^...$
        // lets "app_\n" through while the message says otherwise.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Table prefix must contain only alphanumeric and underscore characters, got "app_' . "\n" . '".',
        );

        new Grammar("app_\n");
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

    public function testFromClauseCarriesTheBindingsOfASubclass(): void
    {
        // The FROM half of the CompiledSql return type had no guard: dropping
        // $from->bindings from the merge left the whole suite green.
        $grammar = new class () extends Grammar {
            protected function compileFrom(string $table): CompiledSql
            {
                return new CompiledSql(
                    ' FROM (SELECT ? AS id) AS ' . $this->quoteTable($table),
                    ['seed'],
                );
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('id', '=', 1)],
        ));

        $this->assertSame('SELECT * FROM (SELECT ? AS id) AS `users` WHERE `id` = ?', $compiled->sql);
        $this->assertSame(['seed', 1], $compiled->bindings);
    }

    public function testWhereRejectsEveryColumnAsAComparisonTarget(): void
    {
        // * names a list of columns, so it cannot be one side of a comparison.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '* names every column, so it only stands where a list of columns does, got *.',
        );

        new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('*', '=', 1)],
        ));
    }

    public function testOrderByRejectsEveryColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            '* names every column, so it only stands where a list of columns does, got users.*.',
        );

        new Grammar()->compileSelect(new SelectSpec(
            from:   'users',
            orders: [new Order('users.*')],
        ));
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

            protected function compileFrom(string $table): CompiledSql
            {
                $compiled = parent::compileFrom($table);

                return new CompiledSql($compiled->sql . '/*from*/', $compiled->bindings);
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

            protected function compileLimit(?int $limit, ?int $offset): CompiledSql
            {
                $compiled = parent::compileLimit($limit, $offset);

                return new CompiledSql($compiled->sql . '/*limit*/', $compiled->bindings);
            }

            protected function compileColumnReference(
                string|Expression $column,
                bool $allowEveryColumn = false,
            ): CompiledSql {
                $compiled = parent::compileColumnReference($column, $allowEveryColumn);

                return new CompiledSql('/*reference*/' . $compiled->sql, $compiled->bindings);
            }

            protected function compileValue(string|int|float|bool|Expression|null $value): CompiledSql
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

    public function testAMembershipTestCarriesTheBindingsOfItsExpressionsInPlaceholderOrder(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [
                new InCondition(
                    Expression::of('IF(?, `a`, `b`)', [1]),
                    ['x', Expression::of('GREATEST(?, ?)', [2, 3])],
                ),
            ],
        ));

        $this->assertSame(
            'SELECT * FROM `users` WHERE IF(?, `a`, `b`) IN (?, GREATEST(?, ?))',
            $compiled->sql,
        );
        $this->assertSame([1, 'x', 2, 3], $compiled->bindings);
    }

    public function testARangeTestCarriesTheBindingsOfItsExpressionsInPlaceholderOrder(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new BetweenCondition(
                Expression::of('IF(?, `a`, `b`)', [0]),
                Expression::of('GREATEST(?, ?)', [1, 2]),
                65,
            )],
        ));

        $this->assertSame(
            'SELECT * FROM `users` WHERE IF(?, `a`, `b`) BETWEEN GREATEST(?, ?) AND ?',
            $compiled->sql,
        );
        $this->assertSame([0, 1, 2, 65], $compiled->bindings);
    }

    public function testEveryKindOfConditionIsOpenToASubclass(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileComparison(Condition $condition): CompiledSql
            {
                $compiled = parent::compileComparison($condition);

                return new CompiledSql('/*comparison*/' . $compiled->sql, $compiled->bindings);
            }

            protected function compileIn(InCondition $condition): CompiledSql
            {
                $compiled = parent::compileIn($condition);

                return new CompiledSql('/*in*/' . $compiled->sql, $compiled->bindings);
            }

            protected function compileBetween(BetweenCondition $condition): CompiledSql
            {
                $compiled = parent::compileBetween($condition);

                return new CompiledSql('/*between*/' . $compiled->sql, $compiled->bindings);
            }

            protected function compileWherePart(WherePart $part): CompiledSql
            {
                $compiled = parent::compileWherePart($part);

                return new CompiledSql('/*part*/' . $compiled->sql, $compiled->bindings);
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [
                new Condition('status', '=', 'active'),
                new InCondition('id', [1, 2]),
                new BetweenCondition('age', 18, 65),
            ],
        ));

        $this->assertSame(
            'SELECT * FROM `users` WHERE /*part*//*comparison*/`status` = ?'
            . ' AND /*part*//*in*/`id` IN (?, ?)'
            . ' AND /*part*//*between*/`age` BETWEEN ? AND ?',
            $compiled->sql,
        );
        $this->assertSame(['active', 1, 2, 18, 65], $compiled->bindings);
    }

    public function testAPartOfTheClauseWithNoCompilingRuleIsRejected(): void
    {
        $unknown = new readonly class () extends WherePart {
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'No rule for compiling ' . get_debug_type($unknown) . ' as part of a WHERE clause.',
        );

        new Grammar()->compileSelect(new SelectSpec(from: 'users', conditions: [$unknown]));
    }

    #[DataProvider('provideSupportedOperators')]
    public function testComparisonAcceptsTheOperatorsTheGrammarWrites(string $operator, string $expected): void
    {
        $this->assertSame($expected, new Grammar()->comparison('id', $operator, 1)->operator);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideSupportedOperators(): array
    {
        return [
            'equal'               => ['=', '='],
            'null safe equal'     => ['<=>', '<=>'],
            'not equal'           => ['!=', '!='],
            'ansi not equal'      => ['<>', '<>'],
            'less'                => ['<', '<'],
            'less or equal'       => ['<=', '<='],
            'greater'             => ['>', '>'],
            'greater or equal'    => ['>=', '>='],
            'like'                => ['LIKE', 'LIKE'],
            'not like'            => ['NOT LIKE', 'NOT LIKE'],
            'regexp'              => ['REGEXP', 'REGEXP'],
            'not regexp'          => ['NOT REGEXP', 'NOT REGEXP'],
            'rlike'               => ['RLIKE', 'RLIKE'],
            'sounds like'         => ['SOUNDS LIKE', 'SOUNDS LIKE'],
            'lowercase like'      => ['like', 'LIKE'],
            'lowercase not like'  => ['not like', 'NOT LIKE'],
            'lowercase regexp'    => ['regexp', 'REGEXP'],
            'mixed case rlike'    => ['RLike', 'RLIKE'],
            'lowercase sounds'    => ['sounds like', 'SOUNDS LIKE'],
        ];
    }

    #[DataProvider('provideRejectedOperators')]
    public function testComparisonRejectsAnOperatorTheGrammarDoesNotWrite(string $operator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported comparison operator "' . $operator . '".');

        new Grammar()->comparison('id', $operator, 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRejectedOperators(): array
    {
        return [
            'sql fragment'      => ['= 1 OR 1'],
            'comment'           => ['= ? --'],
            'empty'             => [''],
            'leading space'     => [' ='],
            'trailing newline'  => ["=\n"],
            'doubled space'     => ['NOT  LIKE'],
            'not yet supported' => ['BETWEEN'],
        ];
    }

    #[DataProvider('provideNullTests')]
    public function testComparisonAcceptsNullWhereTheOperatorReadsIt(string $operator, string $expected): void
    {
        $condition = new Grammar()->comparison('deleted_at', $operator, null);

        $this->assertSame($expected, $condition->operator);
        $this->assertNull($condition->value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideNullTests(): array
    {
        return [
            'is'              => ['IS', 'IS'],
            'is not'          => ['IS NOT', 'IS NOT'],
            'lowercase is'    => ['is', 'IS'],
            'mixed case'      => ['Is Not', 'IS NOT'],
            'null safe equal' => ['<=>', '<=>'],
        ];
    }

    #[DataProvider('provideOperatorsThatCannotReadNull')]
    public function testComparisonRejectsNullWhereTheOperatorCannotReadIt(string $operator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A comparison against null is never true, so it is rejected rather than matching no rows.'
            . ' Write IS or IS NOT to test for NULL.',
        );

        new Grammar()->comparison('deleted_at', $operator, null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideOperatorsThatCannotReadNull(): array
    {
        return [
            'equal'          => ['='],
            'not equal'      => ['!='],
            'ansi not equal' => ['<>'],
            'greater'        => ['>'],
            'like'           => ['LIKE'],
            'regexp'         => ['REGEXP'],
        ];
    }

    #[DataProvider('provideKeywordOperators')]
    public function testComparisonAcceptsABooleanWhereTheOperatorReadsAKeyword(string $operator): void
    {
        $this->assertTrue(new Grammar()->comparison('active', $operator, true)->value);
        $this->assertFalse(new Grammar()->comparison('active', $operator, false)->value);
    }

    #[DataProvider('provideKeywordOperators')]
    public function testComparisonRejectsAValueWhereTheOperatorReadsAKeyword(string $operator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            $operator . ' tests against a keyword, so null, true and false are the only right-hand sides'
            . ' it takes; got int. Use = to compare against a value.',
        );

        new Grammar()->comparison('deleted_at', $operator, 10);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideKeywordOperators(): array
    {
        return [
            'is'     => ['IS'],
            'is not' => ['IS NOT'],
        ];
    }

    public function testComparisonKeepsTheNullSafeEqualTakingAValue(): void
    {
        $this->assertSame(1, new Grammar()->comparison('id', '<=>', 1)->value);
    }

    public function testComparisonDefaultsToAnd(): void
    {
        $this->assertSame(Conjunction::And, new Grammar()->comparison('id', '=', 1)->conjunction);
    }

    public function testComparisonKeepsTheGivenConjunction(): void
    {
        $condition = new Grammar()->comparison('id', '=', 1, Conjunction::Or);

        $this->assertSame(Conjunction::Or, $condition->conjunction);
    }

    public function testComparisonKeepsTheColumnAsGiven(): void
    {
        $column = Expression::of('LOWER(name)');

        $this->assertSame($column, new Grammar()->comparison($column, 'LIKE', '%a%')->column);
    }

    #[DataProvider('provideKeywordComparisons')]
    public function testAKeywordOperatorWritesTheKeywordRatherThanAPlaceholder(
        string $operator,
        string|bool|null $value,
        string $expected,
    ): void {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('active', $operator, $value)],
        ));

        $this->assertSame('SELECT * FROM `users` WHERE `active` ' . $expected, $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    /**
     * @return array<string, array{string, string|bool|null, string}>
     */
    public static function provideKeywordComparisons(): array
    {
        return [
            'is null'      => ['IS', null, 'IS NULL'],
            'is not null'  => ['IS NOT', null, 'IS NOT NULL'],
            'is true'      => ['IS', true, 'IS TRUE'],
            'is false'     => ['IS', false, 'IS FALSE'],
            'is not true'  => ['IS NOT', true, 'IS NOT TRUE'],
            'is not false' => ['IS NOT', false, 'IS NOT FALSE'],
        ];
    }

    public function testARegularExpressionMatchBindsItsPatternLikeAnyOtherValue(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('name', 'REGEXP', '^a')],
        ));

        $this->assertSame('SELECT * FROM `users` WHERE `name` REGEXP ?', $compiled->sql);
        $this->assertSame(['^a'], $compiled->bindings);
    }

    public function testCompilingRejectsAnOperatorTheGrammarDoesNotWrite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported comparison operator "MEMBER OF".');

        new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('id', 'MEMBER OF', 1)],
        ));
    }

    public function testCompilingRejectsAValueWhereTheOperatorReadsAKeyword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'An operator testing against a keyword reads null, true or false on the right, got string.',
        );

        new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('active', 'IS', 'yes')],
        ));
    }

    #[DataProvider('provideOperatorsThatCannotReadNull')]
    public function testCompilingRefusesNullWhereTheOperatorCannotReadIt(string $operator): void
    {
        // A Condition can be built without passing through comparison(), so the
        // refusal is repeated here: `id = ?` bound to null matches no rows, and
        // saying so beats returning an empty result.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A comparison against null is never true, so it is rejected rather than matching no rows.'
            . ' Write IS or IS NOT to test for NULL.',
        );

        new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('id', $operator, null)],
        ));
    }

    public function testCompilingKeepsTheNullSafeEqualReadingNull(): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:       'users',
            conditions: [new Condition('deleted_at', '<=>', null)],
        ));

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` <=> ?', $compiled->sql);
        $this->assertSame([null], $compiled->bindings);
    }

    public function testSubclassCanAddAnOperator(): void
    {
        $grammar = new class () extends Grammar {
            protected function comparisonOperators(): array
            {
                return parent::comparisonOperators() + ['NOT RLIKE' => Operand::Value];
            }
        };

        $condition = $grammar->comparison('name', 'not rlike', '^a');
        $compiled  = $grammar->compileSelect(new SelectSpec(from: 'users', conditions: [$condition]));

        $this->assertSame('SELECT * FROM `users` WHERE `name` NOT RLIKE ?', $compiled->sql);
        $this->assertSame(['^a'], $compiled->bindings);
    }

    public function testSubclassCanReplaceASingleClause(): void
    {
        $grammar = new class () extends Grammar {
            protected function compileLimit(?int $limit, ?int $offset): CompiledSql
            {
                return $limit === null
                    ? new CompiledSql('')
                    : new CompiledSql(' FETCH FIRST ? ROWS ONLY', [$limit]);
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec(from: 'users', limit: 5));

        $this->assertSame('SELECT * FROM `users` FETCH FIRST ? ROWS ONLY', $compiled->sql);
        $this->assertSame([5], $compiled->bindings);
    }

    #[DataProvider('provideLocks')]
    public function testSelectWritesTheLockItWasAskedFor(RowLock $lock, string $expected): void
    {
        $compiled = new Grammar()->compileSelect(new SelectSpec(from: 'users', lock: $lock));

        $this->assertSame('SELECT * FROM `users` ' . $expected, $compiled->sql);
        $this->assertSame([], $compiled->bindings);
    }

    /**
     * @return array<string, array{RowLock, string}>
     */
    public static function provideLocks(): array
    {
        return [
            'for update'             => [RowLock::Update, 'FOR UPDATE'],
            'for update skip locked' => [RowLock::UpdateSkipLocked, 'FOR UPDATE SKIP LOCKED'],
            'for update nowait'      => [RowLock::UpdateNoWait, 'FOR UPDATE NOWAIT'],
            // LOCK IN SHARE MODE rather than FOR SHARE: MariaDB 10.11 rejects
            // FOR SHARE as a syntax error, and both servers take this spelling.
            'shared'                 => [RowLock::Shared, 'LOCK IN SHARE MODE'],
        ];
    }

    public function testSelectWritesTheLockLast(): void
    {
        // The lock closes the statement, so it goes after the row window
        // rather than anywhere it happens to compile.
        $compiled = new Grammar()->compileSelect(new SelectSpec(
            from:   'users',
            orders: [new Order('id', Direction::Ascending)],
            limit:  10,
            offset: 5,
            lock:   RowLock::Update,
        ));

        $this->assertSame(
            'SELECT * FROM `users` ORDER BY `id` ASC LIMIT 10 OFFSET 5 FOR UPDATE',
            $compiled->sql,
        );
    }

    public function testSelectCarriesTheBindingsOfAReplacedLockClause(): void
    {
        // compileLock() returns a CompiledSql rather than a string for the same
        // reason compileLimit() does: a dialect that writes a placeholder here
        // needs somewhere to put the value. Without this the bindings could stop
        // being merged and every other test would stay green.
        $grammar = new class () extends Grammar {
            protected function compileLock(?RowLock $lock): CompiledSql
            {
                return new CompiledSql(' FOR UPDATE WAIT ?', [5]);
            }
        };

        $compiled = $grammar->compileSelect(new SelectSpec(from: 'users', lock: RowLock::Update));

        $this->assertSame('SELECT * FROM `users` FOR UPDATE WAIT ?', $compiled->sql);
        $this->assertSame([5], $compiled->bindings);
    }
}
