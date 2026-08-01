<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Replica;

use PHPUnit\Framework\TestCase;
use Sloop\Database\Replica\DeadReplicaCacheKeys;

final class DeadReplicaCacheKeysTest extends TestCase
{
    public function testServerBuildsKeyWithPrefixHostAndPort(): void
    {
        $this->assertSame(
            'sloop.db.dead.replica1.example:3306',
            DeadReplicaCacheKeys::server('replica1.example', 3306),
        );
    }

    public function testPoolAppendsPoolNameToServerKey(): void
    {
        $this->assertSame(
            'sloop.db.dead.replica1.example:3306.reporting',
            DeadReplicaCacheKeys::pool('replica1.example', 3306, 'reporting'),
        );
    }

    public function testPoolKeyIsPrefixedByServerKey(): void
    {
        $server = DeadReplicaCacheKeys::server('replica1.example', 3306);
        $pool   = DeadReplicaCacheKeys::pool('replica1.example', 3306, 'reporting');

        $this->assertSame($server . '.reporting', $pool);
    }

    public function testServerKeysDifferByHost(): void
    {
        $this->assertNotSame(
            DeadReplicaCacheKeys::server('replica1.example', 3306),
            DeadReplicaCacheKeys::server('replica2.example', 3306),
        );
    }

    public function testServerKeysDifferByPort(): void
    {
        $this->assertNotSame(
            DeadReplicaCacheKeys::server('replica1.example', 3306),
            DeadReplicaCacheKeys::server('replica1.example', 3307),
        );
    }

    public function testPoolKeysDifferByPool(): void
    {
        $this->assertNotSame(
            DeadReplicaCacheKeys::pool('replica1.example', 3306, 'reporting'),
            DeadReplicaCacheKeys::pool('replica1.example', 3306, 'batch'),
        );
    }

    public function testServerKeysDoNotCollideWhenHostAndPortConcatenationIsAmbiguous(): void
    {
        // 区切りの ':' が失われると 'a' + '13306' と 'a1' + '3306' が同一文字列になる
        $this->assertNotSame(
            DeadReplicaCacheKeys::server('a', 13306),
            DeadReplicaCacheKeys::server('a1', 3306),
        );
    }

    public function testPoolKeysDoNotCollideWhenPortAndPoolConcatenationIsAmbiguous(): void
    {
        // 区切りの '.' が失われると '3306' + '1reporting' と '33061' + 'reporting' が同一になる
        $this->assertNotSame(
            DeadReplicaCacheKeys::pool('a', 3306, '1reporting'),
            DeadReplicaCacheKeys::pool('a', 33061, 'reporting'),
        );
    }
}
