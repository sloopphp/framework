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

    #[DataProvider('provideRejectedOperators')]
    public function testRejectsAnythingOutsideTheSupportedOperators(string $operator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported comparison operator "' . $operator . '".');

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
