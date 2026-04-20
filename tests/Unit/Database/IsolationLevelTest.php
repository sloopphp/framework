<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\IsolationLevel;

final class IsolationLevelTest extends TestCase
{
    public function testDefaultProducesEmptySqlStatement(): void
    {
        $this->assertSame('', IsolationLevel::Default->toSqlStatement());
    }

    /**
     * @return array<string, array{IsolationLevel, string}>
     */
    public static function nonDefaultLevelProvider(): array
    {
        return [
            'read uncommitted' => [IsolationLevel::ReadUncommitted, 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED'],
            'read committed'   => [IsolationLevel::ReadCommitted,   'SET TRANSACTION ISOLATION LEVEL READ COMMITTED'],
            'repeatable read'  => [IsolationLevel::RepeatableRead,  'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'],
            'serializable'     => [IsolationLevel::Serializable,    'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE'],
        ];
    }

    #[DataProvider('nonDefaultLevelProvider')]
    public function testNonDefaultLevelProducesSetTransactionStatement(
        IsolationLevel $level,
        string $expected,
    ): void {
        $this->assertSame($expected, $level->toSqlStatement());
    }

    public function testEnumCasesAreExhaustive(): void
    {
        $this->assertSame(
            [
                IsolationLevel::Default,
                IsolationLevel::ReadUncommitted,
                IsolationLevel::ReadCommitted,
                IsolationLevel::RepeatableRead,
                IsolationLevel::Serializable,
            ],
            IsolationLevel::cases(),
        );
    }
}
