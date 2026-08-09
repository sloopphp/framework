<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    private static function envOr(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    public function testCanConnectToDatabase(): void
    {
        $host = self::envOr('DB_HOST', '127.0.0.1');
        $port = self::envOr('DB_PORT', '3306');
        $name = self::envOr('DB_NAME', 'sloop_test');
        $user = self::envOr('DB_USER', 'sloop');
        $pass = self::envOr('DB_PASS', 'secret');

        $pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $stmt = $pdo->query('SELECT 1');
        self::assertNotFalse($stmt);

        $result = $stmt->fetchColumn();

        self::assertSame(1, (int) $result);
    }
}
