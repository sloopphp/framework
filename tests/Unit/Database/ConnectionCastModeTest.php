<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\CastMode;
use Sloop\Database\Connection;
use Sloop\Database\Query\Select;
use Sloop\Tests\Support\ThrowsAssertions;
use UnexpectedValueException;

final class ConnectionCastModeTest extends TestCase
{
    use ThrowsAssertions;

    /**
     * Build a connection whose one statement returns the given rows and column metadata.
     *
     * @param list<array<array-key, mixed>>    $rows Rows the statement hands back under FETCH_ASSOC
     * @param list<array<string, mixed>>|false $meta Column metadata by position, or false for a driver that has none
     */
    private function connectionReturning(array $rows, array|false $meta): Connection
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn($rows);
        $statement->method('columnCount')->willReturn($meta === false ? 1 : \count($meta));
        $statement->method('getColumnMeta')->willReturnCallback(
            static fn (int $index): array|false => $meta === false ? false : $meta[$index],
        );

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new Connection($pdo, 'test');
    }

    /**
     * One column's metadata in the shape PDO's MySQL driver returns it.
     *
     * @param  array<string, mixed> $extra Keys to add or override
     * @return array<string, mixed>
     */
    private function meta(string $name, string $nativeType, array $extra = []): array
    {
        return $extra + ['name' => $name, 'native_type' => $nativeType, 'len' => 0, 'flags' => []];
    }

    public function testOffLeavesValuesAsTheDriverReturnedThem(): void
    {
        $connection = $this->connectionReturning(
            [['created_at' => '2026-04-14 15:30:45']],
            [$this->meta('created_at', 'DATETIME')],
        );

        $rows = $connection->query('SELECT created_at FROM t')->asArray();

        $this->assertSame([['created_at' => '2026-04-14 15:30:45']], $rows);
    }

    public function testOffDoesNotReadColumnMetadata(): void
    {
        // The metadata call is what Off is meant to skip, so a driver that has
        // none must still work as long as nothing is being converted.
        $connection = $this->connectionReturning([['n' => 1]], false);

        $this->assertSame([['n' => 1]], $connection->query('SELECT 1')->asArray());
    }

    public function testDatetimeConvertsTheThreeDateColumnTypes(): void
    {
        $connection = $this->connectionReturning(
            [['d' => '2026-04-14', 'dt' => '2026-04-14 15:30:45', 'ts' => '2026-04-14 15:30:45']],
            [$this->meta('d', 'DATE'), $this->meta('dt', 'DATETIME'), $this->meta('ts', 'TIMESTAMP')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $row = $connection->query('SELECT d, dt, ts FROM t')->first();

        $this->assertNotNull($row);
        $this->assertEquals(new DateTimeImmutable('2026-04-14'), $row['d']);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['dt']);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['ts']);
    }

    public function testDatetimeLeavesOtherColumnTypesAlone(): void
    {
        $connection = $this->connectionReturning(
            [['id' => 7, 'flag' => 1, 'name' => 'ada', 'time' => '15:30:45']],
            [
                $this->meta('id', 'LONG'),
                $this->meta('flag', 'TINY', ['len' => 1]),
                $this->meta('name', 'VAR_STRING'),
                $this->meta('time', 'TIME'),
            ],
        );
        $connection->setCastMode(CastMode::Datetime);

        $this->assertSame(
            [['id' => 7, 'flag' => 1, 'name' => 'ada', 'time' => '15:30:45']],
            $connection->query('SELECT * FROM t')->asArray(),
        );
    }

    public function testAggressiveConvertsAOneWideTinyintToBool(): void
    {
        $connection = $this->connectionReturning(
            [['flag' => 1, 'off' => 0]],
            [$this->meta('flag', 'TINY', ['len' => 1]), $this->meta('off', 'TINY', ['len' => 1])],
        );
        $connection->setCastMode(CastMode::Aggressive);

        $this->assertSame([['flag' => true, 'off' => false]], $connection->query('SELECT * FROM t')->asArray());
    }

    public function testAggressiveLeavesAWiderTinyintAsAnInt(): void
    {
        // TINYINT(4) holds -128..127, so it is a small number rather than a flag.
        $connection = $this->connectionReturning(
            [['n' => 42]],
            [$this->meta('n', 'TINY', ['len' => 4])],
        );
        $connection->setCastMode(CastMode::Aggressive);

        $this->assertSame([['n' => 42]], $connection->query('SELECT n FROM t')->asArray());
    }

    public function testAggressiveAlsoConvertsDates(): void
    {
        // The presets are cumulative: Aggressive is Datetime plus booleans.
        $connection = $this->connectionReturning(
            [['dt' => '2026-04-14 15:30:45']],
            [$this->meta('dt', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Aggressive);

        $row = $connection->query('SELECT dt FROM t')->first();

        $this->assertNotNull($row);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['dt']);
    }

    public function testNullStaysNullWhateverTheColumnType(): void
    {
        $connection = $this->connectionReturning(
            [['dt' => null, 'flag' => null]],
            [$this->meta('dt', 'DATETIME'), $this->meta('flag', 'TINY', ['len' => 1])],
        );
        $connection->setCastMode(CastMode::Aggressive);

        $this->assertSame([['dt' => null, 'flag' => null]], $connection->query('SELECT * FROM t')->asArray());
    }

    public function testPerQueryModeOverridesTheConnectionDefault(): void
    {
        $connection = $this->connectionReturning(
            [['dt' => '2026-04-14 15:30:45']],
            [$this->meta('dt', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $rows = $connection->query('SELECT dt FROM t', [], null, CastMode::Off)->asArray();

        $this->assertSame([['dt' => '2026-04-14 15:30:45']], $rows);
    }

    public function testPerQueryModeCanConvertOnAConnectionLeftAtOff(): void
    {
        $connection = $this->connectionReturning(
            [['dt' => '2026-04-14 15:30:45']],
            [$this->meta('dt', 'DATETIME')],
        );

        $row = $connection->query('SELECT dt FROM t', [], null, CastMode::Datetime)->first();

        $this->assertNotNull($row);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['dt']);
    }

    public function testConversionFailsWhenTheDriverHasNoColumnMetadata(): void
    {
        // Returning the values unconverted would leave the caller with the
        // strings a DateTimeImmutable was asked for, and nothing to notice it by.
        $connection = $this->connectionReturning([['dt' => '2026-04-14 15:30:45']], false);
        $connection->setCastMode(CastMode::Datetime);

        $e = $this->assertThrows(
            RuntimeException::class,
            static fn () => $connection->query('SELECT dt FROM t'),
        );
        $this->assertSame(
            'Connection [test]: the driver reports no metadata for column 0, so casts cannot be applied. '
                . 'Set the pool\'s "casts" to CastMode::Off to read this statement.',
            $e->getMessage(),
        );
    }

    public function testAnUnparsableDateNamesTheColumnAndTheValue(): void
    {
        // A zero date is what a column filled before strict mode was on holds.
        $connection = $this->connectionReturning(
            [['dt' => '0000-00-00 00:00:00']],
            [$this->meta('dt', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $e = $this->assertThrows(
            RuntimeException::class,
            static fn () => $connection->query('SELECT dt FROM t'),
        );
        $this->assertSame(
            'Connection [test]: column "dt" holds "0000-00-00 00:00:00", which is not a date PHP can read.',
            $e->getMessage(),
        );
    }

    public function testTheLastOfTwoColumnsSharingANameDecidesTheConversion(): void
    {
        // FETCH_ASSOC keeps the rightmost of two columns with the same label,
        // so the metadata has to be read in the same direction.
        $connection = $this->connectionReturning(
            [['x' => '2026-04-14 15:30:45']],
            [$this->meta('x', 'VAR_STRING'), $this->meta('x', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $row = $connection->query('SELECT a.x, b.x FROM a JOIN b')->first();

        $this->assertNotNull($row);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['x']);
    }

    public function testABuilderReadsUnderTheConnectionsMode(): void
    {
        $connection = $this->connectionReturning(
            [['dt' => '2026-04-14 15:30:45']],
            [$this->meta('dt', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $row = $connection->select('dt')->from('t')->execute()->first();

        $this->assertNotNull($row);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['dt']);
    }

    public function testABuilderCanNameAModeForItsOwnStatement(): void
    {
        $connection = $this->connectionReturning(
            [['dt' => '2026-04-14 15:30:45']],
            [$this->meta('dt', 'DATETIME')],
        );

        $row = $connection->select('dt')->from('t')->castMode(CastMode::Datetime)->execute()->first();

        $this->assertNotNull($row);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['dt']);
    }

    public function testABuilderCanTurnTheConnectionsModeOffForOneStatement(): void
    {
        $connection = $this->connectionReturning(
            [['dt' => '2026-04-14 15:30:45']],
            [$this->meta('dt', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $row = $connection->select('dt')->from('t')->castMode(CastMode::Off)->execute()->first();

        $this->assertNotNull($row);
        $this->assertSame('2026-04-14 15:30:45', $row['dt']);
    }

    public function testTheModeIsBuilderStateSoTheShortcutsCarryItToo(): void
    {
        // first() and value() run the statement themselves rather than going
        // through execute(), so each has to reach the connection with the mode.
        $connection = $this->connectionReturning(
            [['dt' => '2026-04-14 15:30:45']],
            [$this->meta('dt', 'DATETIME')],
        );
        $select     = fn (): Select => $connection->select('dt')->from('t')->castMode(CastMode::Datetime);
        $expected   = new DateTimeImmutable('2026-04-14 15:30:45');

        $first = $select()->first();
        $this->assertNotNull($first);
        $this->assertEquals($expected, $first['dt']);
        $this->assertEquals($expected, $select()->value('dt'));
        $this->assertEquals([$expected], $select()->pluck('dt'));
    }

    public function testTheModeIsAbsentFromTheCompiledStatement(): void
    {
        // Conversion happens after the rows come back, so nothing about it is
        // written into the SQL.
        $connection = $this->connectionReturning([], [$this->meta('dt', 'DATETIME')]);

        $select = $connection->select('dt')->from('t')->castMode(CastMode::Datetime);

        $this->assertSame('SELECT `dt` FROM `t`', $select->toSql());
        $this->assertSame('SELECT `dt` FROM `t`', $select->toRawSql());
    }

    public function testAWalkRefusesACursorTheCastsConvert(): void
    {
        // The cursor is bound into the statement that reads the next batch, so
        // a DateTimeImmutable cannot carry the walk forward.
        $connection = $this->connectionReturning(
            [['id' => '2026-04-14 15:30:45']],
            [$this->meta('id', 'DATETIME')],
        );

        $e = $this->assertThrows(
            UnexpectedValueException::class,
            static fn () => $connection->select('id')->from('t')
                ->castMode(CastMode::Datetime)
                ->chunkById(10, static fn (): bool => true),
        );
        $this->assertStringContainsString(
            'the cast mode in effect turns it into DateTimeImmutable',
            $e->getMessage(),
        );
    }
}
