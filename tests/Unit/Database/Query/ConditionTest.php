<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\Condition;
use Sloop\Database\Query\Conjunction;
use Sloop\Database\Query\Expression;

final class ConditionTest extends TestCase
{
    public function testDefaultsToAndSoConditionsNarrowTheResult(): void
    {
        $this->assertSame(Conjunction::And, new Condition('id', '=', 1)->conjunction);
    }

    public function testKeepsTheGivenConjunction(): void
    {
        $this->assertSame(Conjunction::Or, new Condition('id', '=', 1, Conjunction::Or)->conjunction);
    }

    public function testKeepsTheColumnAndValueAsGiven(): void
    {
        $value     = Expression::of('NOW()');
        $condition = new Condition('created_at', '<', $value);

        $this->assertSame('created_at', $condition->column);
        $this->assertSame($value, $condition->value);
    }

    public function testKeepsTheOperatorAsGivenBecauseTheGrammarHasAlreadyCheckedIt(): void
    {
        $this->assertSame('NOT LIKE', new Condition('name', 'NOT LIKE', '%a%')->operator);
    }
}
