<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Connection;
use Sloop\Database\Query\ClauseParts;
use Sloop\Database\Query\CommonTableExpression;
use Sloop\Database\Query\SubQuery;
use Sloop\Tests\Support\ThrowsAssertions;

final class CommonTableExpressionTest extends TestCase
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

        $this->connection = new Connection($sqlite, 'cte_spec_test');
    }

    private function body(): SubQuery
    {
        return new SubQuery($this->connection->select('id')->from('users'));
    }

    public function testKeepsWhatItWasGiven(): void
    {
        $commonTable = new CommonTableExpression('recent', $this->body(), ['a', 'b'], recursive: true);

        $this->assertSame('recent', $commonTable->name);
        $this->assertSame(['a', 'b'], $commonTable->columns);
        $this->assertTrue($commonTable->recursive);
    }

    public function testNamesNoColumnsAndIsNotRecursiveUnlessAsked(): void
    {
        $commonTable = new CommonTableExpression('recent', $this->body());

        $this->assertSame([], $commonTable->columns);
        $this->assertFalse($commonTable->recursive);
    }

    public function testReadsTheColumnsAsAListWhateverKeysTheyCameUnder(): void
    {
        $commonTable = new CommonTableExpression('recent', $this->body(), [3 => 'a', 1 => 'b']);

        $this->assertSame(['a', 'b'], $commonTable->columns);
    }

    public function testRefusesANameWithAnEmptySegment(): void
    {
        $body = $this->body();

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): CommonTableExpression => new CommonTableExpression('', $body),
        );

        $this->assertSame('Identifier must not contain an empty segment, got .', $error->getMessage());
    }

    public function testAClauseRefusesSomethingThatNamesNoStatement(): void
    {
        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => ClauseParts::toCommonTables(['recent']),
        );

        $this->assertSame(
            'A WITH clause holds CommonTableExpression instances, got string at index 0.',
            $error->getMessage(),
        );
    }

    public function testAClauseReportsThePositionOfWhatItRefusedRatherThanItsKey(): void
    {
        // The keys of the array a clause is built from carry no meaning, so a
        // caller counting to find what was refused would be counting positions.
        $first = new CommonTableExpression('first', $this->body());

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => ClauseParts::toCommonTables([6 => $first, 9 => 'second']),
        );

        $this->assertSame(
            'A WITH clause holds CommonTableExpression instances, got string at index 1.',
            $error->getMessage(),
        );
    }

    public function testAColumnIsReportedByItsPositionRatherThanItsKey(): void
    {
        $body = $this->body();

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): CommonTableExpression => new CommonTableExpression(
                'recent',
                $body,
                [4 => 'a', 8 => 42],
            ),
        );

        $this->assertSame(
            'A column of a statement in a WITH clause is named with a string, got int at index 1.',
            $error->getMessage(),
        );
    }

    public function testAClauseRefusesTheSameNameTwice(): void
    {
        $first  = new CommonTableExpression('recent', $this->body());
        $second = new CommonTableExpression('recent', $this->body());

        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): array => ClauseParts::toCommonTables([$first, $second]),
        );

        $this->assertSame(
            'A WITH clause names each statement once, and recent is named twice.',
            $error->getMessage(),
        );
    }

    public function testAClauseReadsTheStatementsAsAListWhateverKeysTheyCameUnder(): void
    {
        $first  = new CommonTableExpression('first', $this->body());
        $second = new CommonTableExpression('second', $this->body());

        $this->assertSame([$first, $second], ClauseParts::toCommonTables([7 => $first, 2 => $second]));
    }
}
