<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Dialect;

final class DialectTest extends TestCase
{
    /**
     * @return array<string, array{string, Dialect}>
     */
    public static function versionProvider(): array
    {
        return [
            'MySQL 8.0'                        => ['8.0.37', Dialect::MySQL],
            'MySQL 5.7'                        => ['5.7.44', Dialect::MySQL],
            'MariaDB standard'                 => ['10.11.11-MariaDB', Dialect::MariaDB],
            'MariaDB with compat prefix'       => ['5.5.5-10.11.11-MariaDB', Dialect::MariaDB],
            'MariaDB with distro tag'          => ['10.6.22-MariaDB-ubu2004', Dialect::MariaDB],
            'empty string'                     => ['', Dialect::MySQL],
            'unknown version'                  => ['unknown', Dialect::MySQL],
            // Detection is intentionally case-sensitive: real MariaDB servers
            // return the exact marker "MariaDB" from SELECT VERSION(), so
            // accepting lowercase would risk false positives on other strings.
            'lowercase mariadb treated as MySQL' => ['10.11.11-mariadb', Dialect::MySQL],
        ];
    }

    #[DataProvider('versionProvider')]
    public function testDetectFromVersionString(string $version, Dialect $expected): void
    {
        $this->assertSame($expected, Dialect::detect($version));
    }

    public function testEnumHasExpectedCases(): void
    {
        $this->assertSame(
            [Dialect::MySQL, Dialect::MariaDB],
            Dialect::cases(),
        );
    }
}
