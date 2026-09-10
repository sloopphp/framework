<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\Conjunction;
use Sloop\Database\Query\ExistsCondition;
use Sloop\Database\Query\SubQuery;

final class ExistsConditionTest extends TestCase
{
    private SubQuery $query;

    protected function setUp(): void
    {
        $sqlite = new Sqlite('sqlite::memory:', null, null, [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        ]);
        $sqlite->createFunction('version', static fn (): string => '8.0.37');

        $connection  = new Connection($sqlite, 'exists_condition_test');
        $this->query = new SubQuery($connection->select('id')->from('orders'));
    }

    public function testKeepsTheStatementAsGiven(): void
    {
        $condition = new ExistsCondition($this->query);

        $this->assertSame($this->query, $condition->query);
    }

    public function testAsksWhetherARowExistsUnlessToldOtherwise(): void
    {
        $this->assertFalse((new ExistsCondition($this->query))->negated);
    }

    public function testKeepsTheNegation(): void
    {
        $this->assertTrue((new ExistsCondition($this->query, negated: true))->negated);
    }

    public function testDefaultsToAndSoConditionsNarrowTheResult(): void
    {
        $this->assertSame(Conjunction::And, (new ExistsCondition($this->query))->conjunction);
    }

    public function testKeepsTheGivenConjunction(): void
    {
        $condition = new ExistsCondition($this->query, conjunction: Conjunction::Or);

        $this->assertSame(Conjunction::Or, $condition->conjunction);
    }
}
