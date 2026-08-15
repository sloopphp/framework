<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\Conjunction;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\InCondition;

final class InConditionTest extends TestCase
{
    public function testKeepsTheColumnAndValuesAsGiven(): void
    {
        $condition = new InCondition('status', ['active', 'pending']);

        $this->assertSame('status', $condition->column);
        $this->assertSame(['active', 'pending'], $condition->values);
    }

    public function testTestsForMembershipUnlessToldOtherwise(): void
    {
        $this->assertFalse((new InCondition('status', ['active']))->negated);
    }

    public function testKeepsTheNegation(): void
    {
        $this->assertTrue((new InCondition('status', ['active'], negated: true))->negated);
    }

    public function testDefaultsToAndSoConditionsNarrowTheResult(): void
    {
        $this->assertSame(Conjunction::And, (new InCondition('status', ['active']))->conjunction);
    }

    public function testKeepsTheGivenConjunction(): void
    {
        $condition = new InCondition('status', ['active'], conjunction: Conjunction::Or);

        $this->assertSame(Conjunction::Or, $condition->conjunction);
    }

    public function testAcceptsAnExpressionAmongTheValues(): void
    {
        $expression = Expression::of('NOW()');

        $this->assertSame([$expression], (new InCondition('created_at', [$expression]))->values);
    }

    public function testReindexesTheValuesSoTheirOrderMatchesThePlaceholders(): void
    {
        $condition = new InCondition('id', [7 => 'a', 3 => 'b']);

        $this->assertSame(['a', 'b'], $condition->values);
    }

    public function testRejectsAnEmptySet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('An IN test needs at least one value');

        new InCondition('status', []);
    }

    /**
     * @param array<int|string, mixed> $values
     */
    #[DataProvider('provideSetsHoldingNull')]
    public function testRejectsNullAmongTheValues(array $values, int $expectedIndex): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Found at index ' . $expectedIndex . '.');

        new InCondition('status', $values);
    }

    /**
     * @return array<string, array{array<int|string, mixed>, int}>
     */
    public static function provideSetsHoldingNull(): array
    {
        return [
            'only null'  => [[null], 0],
            'null first' => [[null, 'active'], 0],
            'null last'  => [['active', null], 1],
        ];
    }

    public function testTheReportedPositionIsThePlaceholderPositionNotTheOriginalKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Found at index 1.');

        new InCondition('status', ['first' => 'active', 'second' => null]);
    }

    public function testExplainsWhyNullCannotStandAmongTheValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('makes NOT IN match no rows at all');

        new InCondition('status', ['active', null]);
    }

    public function testRejectsAValueThatCannotBeCompared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must be scalar or an Expression, got array at index 1.');

        new InCondition('status', ['active', ['nested']]);
    }
}
