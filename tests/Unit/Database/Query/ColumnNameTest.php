<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\ColumnName;
use Sloop\Database\Query\Expression;
use Sloop\Tests\Support\ThrowsAssertions;

final class ColumnNameTest extends TestCase
{
    use ThrowsAssertions;

    public function testKeepsTheNameAsGivenSoAGrammarQuotesItLater(): void
    {
        $column = new ColumnName('users.id');

        $this->assertSame('users.id', $column->name);
    }

    public function testRefusesANameWithAnEmptySegment(): void
    {
        $error = $this->assertThrows(
            InvalidArgumentException::class,
            static fn (): ColumnName => new ColumnName('users.'),
        );

        $this->assertSame('Identifier must not contain an empty segment, got users..', $error->getMessage());
    }

    public function testExpressionColumnBuildsOne(): void
    {
        $column = Expression::column('users.id');

        $this->assertInstanceOf(ColumnName::class, $column);
        $this->assertSame('users.id', $column->name);
    }
}
