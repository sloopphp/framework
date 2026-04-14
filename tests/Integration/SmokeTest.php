<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testCanConnectToDatabase(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'sloop_test';
        $user = getenv('DB_USER') ?: 'sloop';
        $pass = getenv('DB_PASS') ?: 'secret';

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
