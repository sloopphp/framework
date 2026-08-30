<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use DateMalformedStringException;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    public function testAColumnWhoseMetadataCarriesNoNativeTypeIsLeftAlone(): void
    {
        // The accepted metadata shape declares native_type optional, so a
        // column can arrive without one and then there is nothing to decide
        // on. The value is date shaped because that is the one a misfiring
        // decision would convert. meta() always sets the key, so the array is
        // written out here instead.
        $connection = $this->connectionReturning(
            [['x' => '2026-04-14 15:30:45']],
            [['name' => 'x', 'len' => 0, 'flags' => []]],
        );
        $connection->setCastMode(CastMode::Aggressive);

        $this->assertSame([['x' => '2026-04-14 15:30:45']], $connection->query('SELECT x FROM t')->asArray());
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
            UnexpectedValueException::class,
            static fn () => $connection->query('SELECT dt FROM t'),
        );
        $this->assertSame(
            'Connection [test]: the driver reports no metadata for column 0, so casts cannot be applied. '
                . 'Set the pool\'s "casts" to CastMode::Off to read this statement.',
            $e->getMessage(),
        );
    }

    public function testALaterColumnSharingANameCanAlsoDecideAgainstConverting(): void
    {
        // The mirror of the case below. The rightmost column is the one whose
        // value survives, so when the preset leaves that column alone, the
        // earlier column's conversion must not be applied to what is left.
        $connection = $this->connectionReturning(
            [['x' => '2020']],
            [$this->meta('x', 'DATETIME'), $this->meta('x', 'VAR_STRING')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $this->assertSame([['x' => '2020']], $connection->query('SELECT a.x, b.x FROM a JOIN b')->asArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableDateProvider(): array
    {
        return [
            // The first three the parser reads as the current time rather
            // than refusing, which is what the shape check is for. They are
            // not one class of value to PHP: trim() drops a space and a tab
            // but keeps a non-breaking space, and the parser refuses an
            // ideographic space while accepting the other two.
            'empty'             => [''],
            'space'             => [' '],
            'tab'               => ["\t"],
            'non-breaking'      => ["\u{00A0}"],
            'space then nbsp'   => [" \u{00A0}"],
            'ideographic'       => ["\u{3000}"],
            // These have the shape but are not dates. The parser reads the
            // first as -0001-11-30 and rolls the second into March.
            'zero date'         => ['0000-00-00 00:00:00'],
            'zero date no time' => ['0000-00-00'],
            'rolls over'        => ['2026-02-31 00:00:00'],
            // Shaped like a date but out of range on its own terms.
            'month 13'          => ['2026-13-01 00:00:00'],
            // Neither the shape nor the parser accepts these.
            'not a date'        => ['not a date at all'],
            'trailing text'     => ['2026-04-14 15:30:45 and more'],
            'iso T separator'   => ['2026-04-14T15:30:45'],
            // Wider than any column can declare, so no server writes it.
            'seven digit frac'  => ['2026-04-14 15:30:45.1234567'],
        ];
    }

    #[DataProvider('unreadableDateProvider')]
    public function testAValueThatIsNotAReadableDateFails(string $value): void
    {
        $connection = $this->connectionReturning([['dt' => $value]], [$this->meta('dt', 'DATETIME')]);
        $connection->setCastMode(CastMode::Datetime);

        $e = $this->assertThrows(
            UnexpectedValueException::class,
            static fn () => $connection->query('SELECT dt FROM t'),
        );
        $this->assertSame(
            'Connection [test]: column "dt" holds "' . $value . '", which is not a date PHP can read.',
            $e->getMessage(),
        );
        $this->assertSame(0, $e->getCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fractionWidthProvider(): array
    {
        // A column declares its own width, and both servers write the value out
        // padded to it, so every width from none to six arrives at some point.
        return [
            'no fraction' => ['2026-04-14 15:30:45'],
            'one digit'   => ['2026-04-14 15:30:45.1'],
            'three'       => ['2026-04-14 15:30:45.123'],
            'six'         => ['2026-04-14 15:30:45.123456'],
        ];
    }

    #[DataProvider('fractionWidthProvider')]
    public function testEveryFractionWidthAColumnCanDeclareIsRead(string $value): void
    {
        $connection = $this->connectionReturning([['dt' => $value]], [$this->meta('dt', 'DATETIME')]);
        $connection->setCastMode(CastMode::Datetime);

        $row = $connection->query('SELECT dt FROM t')->first();

        $this->assertNotNull($row);
        $this->assertEquals(new DateTimeImmutable($value), $row['dt']);
    }

    public function testAShapeCheckThatCannotRunIsReportedAsSuchRatherThanAsABadValue(): void
    {
        // PCRE gives up on its own limits and returns false, which is not the
        // same answer as "this is not a date". Naming the limit is what keeps
        // the reader from going after data that was never looked at.
        $connection = $this->connectionReturning(
            [['dt' => '2026-04-14 15:30:45']],
            [$this->meta('dt', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $previous = ini_set('pcre.backtrack_limit', '1');
        self::assertIsString($previous);

        try {
            $e = $this->assertThrows(
                UnexpectedValueException::class,
                static fn () => $connection->query('SELECT dt FROM t'),
            );
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }

        $this->assertSame(
            'Connection [test]: the check for date columns could not run (Backtrack limit exhausted),'
                . ' so column "dt" was left unread.',
            $e->getMessage(),
        );
    }

    public function testAValueTheParserRefusesCarriesItsFailureAsTheCause(): void
    {
        // This one has the shape, so it reaches the parser and is refused
        // there. Keeping the parser's own failure as the cause is what lets a
        // caller see which part of the value it objected to.
        $connection = $this->connectionReturning(
            [['dt' => '2026-13-01 00:00:00']],
            [$this->meta('dt', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $e = $this->assertThrows(
            UnexpectedValueException::class,
            static fn () => $connection->query('SELECT dt FROM t'),
        );
        $this->assertInstanceOf(DateMalformedStringException::class, $e->getPrevious());
    }

    public function testAValueRefusedByItsShapeHasNoCause(): void
    {
        // The mirror: nothing reached the parser, so there is no failure to
        // carry. Pinned so that the two paths stay distinguishable.
        $connection = $this->connectionReturning(
            [['dt' => 'not a date at all']],
            [$this->meta('dt', 'DATETIME')],
        );
        $connection->setCastMode(CastMode::Datetime);

        $e = $this->assertThrows(
            UnexpectedValueException::class,
            static fn () => $connection->query('SELECT dt FROM t'),
        );
        $this->assertNull($e->getPrevious());
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
        $this->assertSame(
            'chunkById() walks by "id", and the cast mode in effect turns it into DateTimeImmutable.'
                . ' The walk binds this value into the statement that reads the next batch, so it has to be'
                . ' a number or a string. Walk by a column the casts leave alone, or read this statement'
                . ' under CastMode::Off.',
            $e->getMessage(),
        );
    }
}
