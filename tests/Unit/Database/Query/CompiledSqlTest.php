<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Query;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Query\CompiledSql;
use stdClass;

final class CompiledSqlTest extends TestCase
{
    public function testKeepsSqlAndBindingsAsGiven(): void
    {
        $compiled = new CompiledSql('SELECT ?', [1]);

        $this->assertSame('SELECT ?', $compiled->sql);
        $this->assertSame([1], $compiled->bindings);
    }

    public function testAcceptsEveryBindableScalarAndNull(): void
    {
        $this->assertSame(
            [1, 1.5, 'x', true, null],
            (new CompiledSql('SELECT ?, ?, ?, ?, ?', [1, 1.5, 'x', true, null]))->bindings,
        );
    }

    public function testRejectsBindingsThatAreNotAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Bindings must be a list, so that their order matches the placeholders in the SQL.',
        );

        new CompiledSql('SELECT ?, ?', [0 => 1, 2 => 2]);
    }

    public function testRejectsABindingPdoCannotSend(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Bindings must be scalar or null, got stdClass at index 1.');

        new CompiledSql('SELECT ?, ?', [1, new stdClass()]);
    }
}
