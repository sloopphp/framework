<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\Condition;
use Sloop\Database\Query\Conjunction;
use Sloop\Database\Query\Expression;

final class ConditionTest extends TestCase
{
    public function testDefaultsToAndSoConditionsNarrowTheResult(): void
    {
        $this->assertSame(Conjunction::And, (new Condition('id', '=', 1))->conjunction);
    }

    public function testKeepsTheGivenConjunction(): void
    {
        $this->assertSame(Conjunction::Or, (new Condition('id', '=', 1, Conjunction::Or))->conjunction);
    }

    public function testKeepsTheColumnAndValueAsGiven(): void
    {
        $value     = Expression::of('NOW()');
        $condition = new Condition('created_at', '<', $value);

        $this->assertSame('created_at', $condition->column);
        $this->assertSame($value, $condition->value);
    }

    #[DataProvider('provideSupportedOperators')]
    public function testAcceptsSupportedOperators(string $operator, string $expectedCanonical): void
    {
        $this->assertSame($expectedCanonical, (new Condition('id', $operator, 1))->operator);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideSupportedOperators(): array
    {
        return [
            'equal'              => ['=', '='],
            'null safe equal'    => ['<=>', '<=>'],
            'not equal'          => ['!=', '!='],
            'ansi not equal'     => ['<>', '<>'],
            'less'               => ['<', '<'],
            'less or equal'      => ['<=', '<='],
            'greater'            => ['>', '>'],
            'greater or equal'   => ['>=', '>='],
            'like'               => ['LIKE', 'LIKE'],
            'not like'           => ['NOT LIKE', 'NOT LIKE'],
            'lowercase like'     => ['like', 'LIKE'],
            'lowercase not like' => ['not like', 'NOT LIKE'],
        ];
    }

    #[DataProvider('provideNullTests')]
    public function testAcceptsNullWhereTheOperatorReadsIt(string $operator, string $expectedCanonical): void
    {
        $condition = new Condition('deleted_at', $operator, null);

        $this->assertSame($expectedCanonical, $condition->operator);
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
    public function testRejectsNullWhereTheOperatorCannotReadIt(string $operator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A comparison against null is never true, so it is rejected rather than matching no rows.'
            . ' Write IS or IS NOT to test for NULL.',
        );

        new Condition('deleted_at', $operator, null);
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
        ];
    }

    #[DataProvider('provideKeywordOperators')]
    public function testRejectsAValueWhereTheOperatorExpectsTheNullKeyword(string $operator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            $operator . ' tests for NULL, so null is the only right-hand side it takes; got int.'
            . ' Use = to compare against a value.',
        );

        new Condition('deleted_at', $operator, 10);
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

    public function testTheNullSafeEqualStillTakesAValue(): void
    {
        $this->assertSame(1, (new Condition('id', '<=>', 1))->value);
    }

    #[DataProvider('provideRejectedOperators')]
    public function testRejectsAnythingOutsideTheSupportedOperators(string $operator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported comparison operator "' . $operator . '".');

        new Condition('id', $operator, 1);
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
            'not yet supported' => ['BETWEEN'],
        ];
    }
}
