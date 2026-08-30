<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use DateTimeImmutable;
use Sloop\Database\CastMode;
use Sloop\Database\Connection;
use Sloop\Tests\Support\IntegrationTestCase;

// The unit tests build the column metadata by hand, so what they show is that
// the conversion follows the metadata. What only a real server can answer is
// whether the metadata says what the conversion expects: the native type names
// are the driver's, not the framework's, and the suite runs against two servers
// that could disagree on them.
final class SelectCastModeTest extends IntegrationTestCase
{
    private const string TABLE = 'sloop_cast_rows';

    private Connection $connection;

    public static function setUpBeforeClass(): void
    {
        $connection = static::openConnection();
        $connection->statement('DROP TABLE IF EXISTS ' . self::TABLE);
        $connection->statement(
            'CREATE TABLE ' . self::TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL PRIMARY KEY, '
                . 'd DATE NOT NULL, '
                . 'dt DATETIME NOT NULL, '
                . 'dt6 DATETIME(6) NOT NULL, '
                . 'ts TIMESTAMP NOT NULL, '
                . 'tm TIME NOT NULL, '
                . 'yr YEAR NOT NULL, '
                . 'flag TINYINT(1) NOT NULL, '
                . 'small TINYINT(4) NOT NULL, '
                . 'nullable_dt DATETIME NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->statement(
            'INSERT INTO ' . self::TABLE
                . ' (id, d, dt, dt6, ts, tm, yr, flag, small, nullable_dt) VALUES'
                . ' (1, ?, ?, ?, ?, ?, ?, 1, 42, NULL),'
                . ' (2, ?, ?, ?, ?, ?, ?, 0, 7, ?)',
            [
                '2026-04-14', '2026-04-14 15:30:45', '2026-04-14 15:30:45.123456',
                '2026-04-14 15:30:45', '15:30:45', 2026,
                '2026-05-20', '2026-05-20 01:02:03', '2026-05-20 01:02:03.000001',
                '2026-05-20 01:02:03', '01:02:03', 2020,
                '2026-06-01 12:00:00',
            ],
        );
    }

    public static function tearDownAfterClass(): void
    {
        static::openConnection()->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function setUp(): void
    {
        $this->connection = static::openConnection();
    }

    public function testTheDriverReturnsEveryValueAsAStringOrIntUnderOff(): void
    {
        // The baseline the presets are measured against: this is what the
        // driver hands back once emulated prepares and stringified fetches
        // are both off, and it is what Off keeps.
        $row = $this->connection->select()->from(self::TABLE)->where('id', 1)->first();

        $this->assertNotNull($row);
        $this->assertSame('2026-04-14', $row['d']);
        $this->assertSame('2026-04-14 15:30:45', $row['dt']);
        $this->assertSame('2026-04-14 15:30:45.123456', $row['dt6']);
        $this->assertSame('2026-04-14 15:30:45', $row['ts']);
        $this->assertSame('15:30:45', $row['tm']);
        // YEAR reads back as a string, not the int the column suggests.
        $this->assertSame('2026', $row['yr']);
        $this->assertSame(1, $row['flag']);
        $this->assertSame(42, $row['small']);
    }

    public function testDatetimeConvertsTheDateColumnsARealServerReports(): void
    {
        $row = $this->connection->select()->from(self::TABLE)
            ->where('id', 1)
            ->castMode(CastMode::Datetime)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(new DateTimeImmutable('2026-04-14'), $row['d']);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['dt']);
        $this->assertEquals(new DateTimeImmutable('2026-04-14 15:30:45'), $row['ts']);
    }

    public function testASubSecondDatetimeKeepsItsFraction(): void
    {
        // DATETIME(6) reports the same native type as DATETIME with a wider
        // length, so the fraction survives only if the value is parsed rather
        // than truncated to the column type.
        $row = $this->connection->select('dt6')->from(self::TABLE)
            ->where('id', 1)
            ->castMode(CastMode::Datetime)
            ->first();

        $this->assertNotNull($row);
        $this->assertInstanceOf(DateTimeImmutable::class, $row['dt6']);
        $this->assertSame('123456', $row['dt6']->format('u'));
    }

    public function testDatetimeLeavesTimeAndYearAlone(): void
    {
        // TIME and YEAR are dates in the loose sense and would parse, but they
        // carry no day, so reading them as a timestamp would invent one.
        $row = $this->connection->select('tm', 'yr')->from(self::TABLE)
            ->where('id', 1)
            ->castMode(CastMode::Datetime)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('15:30:45', $row['tm']);
        // YEAR reads back as a string, not the int the column suggests.
        $this->assertSame('2026', $row['yr']);
    }

    public function testDatetimeLeavesAOneWideTinyintAsAnInt(): void
    {
        $row = $this->connection->select('flag')->from(self::TABLE)
            ->where('id', 1)
            ->castMode(CastMode::Datetime)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(1, $row['flag']);
    }

    public function testAggressiveTellsAFlagFromASmallNumber(): void
    {
        // Both columns report the same native type; the width is what separates
        // the flag from the number, and a real server is what confirms it.
        $rows = $this->connection->select('id', 'flag', 'small')->from(self::TABLE)
            ->orderBy('id')
            ->castMode(CastMode::Aggressive)
            ->execute()
            ->asArray();

        $this->assertSame(
            [
                ['id' => 1, 'flag' => true, 'small' => 42],
                ['id' => 2, 'flag' => false, 'small' => 7],
            ],
            $rows,
        );
    }

    public function testANullDateStaysNull(): void
    {
        $rows = $this->connection->select('id', 'nullable_dt')->from(self::TABLE)
            ->orderBy('id')
            ->castMode(CastMode::Datetime)
            ->execute()
            ->asArray();

        $this->assertNull($rows[0]['nullable_dt']);
        $this->assertEquals(new DateTimeImmutable('2026-06-01 12:00:00'), $rows[1]['nullable_dt']);
    }

    public function testAnExpressionColumnIsLeftAloneWhenTheServerReportsNoTypeForIt(): void
    {
        // A computed column is where the metadata is least like a table column,
        // and reading it is what shows the conversion does not depend on there
        // being a native type for every column.
        $row = $this->connection->select('id')->from(self::TABLE)
            ->where('id', 1)
            ->castMode(CastMode::Aggressive)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(1, $row['id']);
    }
}
