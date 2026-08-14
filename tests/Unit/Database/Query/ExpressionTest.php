<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\Expression;
use stdClass;

final class ExpressionTest extends TestCase
{
    public function testOfKeepsSqlUnchanged(): void
    {
        $this->assertSame('NOW()', Expression::of('NOW()')->sql());
    }

    public function testOfDefaultsToNoBindings(): void
    {
        $this->assertSame([], Expression::of('NOW()')->bindings());
    }

    public function testOfKeepsBindingsInGivenOrder(): void
    {
        $expression = Expression::of('GREATEST(?, ?)', [3, 7]);

        $this->assertSame('GREATEST(?, ?)', $expression->sql());
        $this->assertSame([3, 7], $expression->bindings());
    }

    public function testOfDoesNotValidatePlaceholderCountAgainstBindings(): void
    {
        $expression = Expression::of("CONCAT(name, '?')", ['unused']);

        $this->assertSame("CONCAT(name, '?')", $expression->sql());
        $this->assertSame(['unused'], $expression->bindings());
    }

    public function testOfRejectsListWithGapsAsBindings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Bindings must be a list, so that their order matches the placeholders in the SQL.',
        );

        Expression::of('GREATEST(?, ?)', [0 => 3, 2 => 7]);
    }

    public function testOfRejectsStringKeyedBindings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Bindings must be a list, so that their order matches the placeholders in the SQL.',
        );

        Expression::of('GREATEST(:a, :b)', ['a' => 3, 'b' => 7]);
    }

    public function testOfAcceptsEveryBindableScalarAndNull(): void
    {
        $expression = Expression::of('VALUES (?, ?, ?, ?, ?)', [1, 1.5, 'text', true, null]);

        $this->assertSame([1, 1.5, 'text', true, null], $expression->bindings());
    }

    public function testOfRejectsObjectBinding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Bindings must be scalar or null, got stdClass at index 1.');

        Expression::of('GREATEST(?, ?)', [3, new stdClass()]);
    }

    public function testFieldQuotesColumnAndBindsValues(): void
    {
        $expression = Expression::field('status', ['active', 'pending', 'done']);

        $this->assertSame('FIELD(`status`, ?, ?, ?)', $expression->sql());
        $this->assertSame(['active', 'pending', 'done'], $expression->bindings());
    }

    public function testFieldKeepsValueOrder(): void
    {
        $this->assertSame([3, 1, 2], Expression::field('rank', [3, 1, 2])->bindings());
    }

    public function testFieldReindexesValuesGivenWithStringKeys(): void
    {
        $expression = Expression::field('status', ['a' => 'active', 'b' => 'pending']);

        $this->assertSame('FIELD(`status`, ?, ?)', $expression->sql());
        $this->assertSame(['active', 'pending'], $expression->bindings());
    }

    public function testFieldRejectsArrayValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Values must be scalar or null, got array at index 1.');

        Expression::field('status', ['active', ['nested']]);
    }

    public function testFieldReportsThePlaceholderPositionNotTheOriginalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Values must be scalar or null, got stdClass at index 1.');

        Expression::field('status', ['first' => 'active', 'second' => new stdClass()]);
    }

    public function testFieldRejectsEmptyValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('FIELD() requires at least one value.');

        Expression::field('status', []);
    }

    public function testEltInlinesIntegerPosition(): void
    {
        $expression = Expression::elt(3, ['first', 'second']);

        $this->assertSame('ELT(3, ?, ?)', $expression->sql());
        $this->assertSame(['first', 'second'], $expression->bindings());
    }

    public function testEltEmbedsExpressionPositionAsSql(): void
    {
        $position   = Expression::field('status', ['active', 'done']);
        $expression = Expression::elt($position, ['A', 'B', 'C']);

        $this->assertSame('ELT(FIELD(`status`, ?, ?), ?, ?, ?)', $expression->sql());
        $this->assertSame(['active', 'done', 'A', 'B', 'C'], $expression->bindings());
    }

    public function testEltEmbedsExpressionPositionCarryingNoBindings(): void
    {
        $expression = Expression::elt(Expression::of('NOW()'), ['first', 'second']);

        $this->assertSame('ELT(NOW(), ?, ?)', $expression->sql());
        $this->assertSame(['first', 'second'], $expression->bindings());
    }

    public function testEltRejectsObjectValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Values must be scalar or null, got stdClass at index 0.');

        Expression::elt(1, [new stdClass(), 'second']);
    }

    public function testEltReindexesValuesGivenWithStringKeys(): void
    {
        $expression = Expression::elt(1, ['a' => 'first', 'b' => 'second']);

        $this->assertSame('ELT(1, ?, ?)', $expression->sql());
        $this->assertSame(['first', 'second'], $expression->bindings());
    }

    public function testEltRejectsEmptyValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('ELT() requires at least one value.');

        Expression::elt(1, []);
    }

    public function testIncrementDefaultsToOne(): void
    {
        $expression = Expression::increment('score');

        $this->assertSame('`score` + 1', $expression->sql());
        $this->assertSame([], $expression->bindings());
    }

    public function testIncrementUsesGivenAmount(): void
    {
        $this->assertSame('`score` + 10', Expression::increment('score', 10)->sql());
    }

    public function testIncrementKeepsNegativeAmountAsWritten(): void
    {
        $this->assertSame('`score` + -3', Expression::increment('score', -3)->sql());
    }

    public function testDecrementDefaultsToOne(): void
    {
        $expression = Expression::decrement('stock');

        $this->assertSame('`stock` - 1', $expression->sql());
        $this->assertSame([], $expression->bindings());
    }

    public function testDecrementUsesGivenAmount(): void
    {
        $this->assertSame('`stock` - 5', Expression::decrement('stock', 5)->sql());
    }

    public function testDecrementKeepsNegativeAmountAsWritten(): void
    {
        $this->assertSame('`stock` - -5', Expression::decrement('stock', -5)->sql());
    }

    #[DataProvider('provideColumnsAndQuotedForms')]
    public function testColumnQuoting(string $column, string $expectedQuoted): void
    {
        $this->assertSame($expectedQuoted . ' + 1', Expression::increment($column)->sql());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideColumnsAndQuotedForms(): array
    {
        return [
            'plain column'          => ['score', '`score`'],
            'qualified column'      => ['users.score', '`users`.`score`'],
            'three part identifier' => ['app.users.score', '`app`.`users`.`score`'],
            'embedded backtick'     => ['sco`re', '`sco``re`'],
            'backtick and dot'      => ['a`b.c', '`a``b`.`c`'],
            'multibyte column'      => ['得点', '`得点`'],
            'space in column'       => ['my score', '`my score`'],
        ];
    }

    #[DataProvider('provideInvalidColumns')]
    public function testColumnQuotingRejectsEmptySegments(string $column, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        Expression::increment($column);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidColumns(): array
    {
        $prefix = 'Identifier must not contain an empty segment, got ';

        return [
            'empty string'    => ['', $prefix . '.'],
            'leading dot'     => ['.score', $prefix . '.score.'],
            'trailing dot'    => ['users.', $prefix . 'users..'],
            'consecutive dot' => ['users..score', $prefix . 'users..score.'],
            'only a dot'      => ['.', $prefix . '..'],
        ];
    }

    public function testFieldRejectsEmptyColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Identifier must not contain an empty segment, got .');

        Expression::field('', ['active']);
    }
}
