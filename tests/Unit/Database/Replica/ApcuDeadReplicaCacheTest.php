<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Replica;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Replica\ApcuDeadReplicaCache;
use Sloop\Database\Replica\DeadReplicaCache;
use Sloop\Database\Replica\DeadReplicaCacheKeys;

#[RequiresPhpExtension('apcu')]
final class ApcuDeadReplicaCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ApcuDeadReplicaCache::isAvailable()) {
            $this->markTestSkipped('APCu is installed but disabled in this SAPI (set apc.enable_cli=1 for CLI).');
        }

        apcu_clear_cache();
    }

    public function testIsAvailableReturnsTrueWhenApcuEnabled(): void
    {
        $this->assertTrue(ApcuDeadReplicaCache::isAvailable());
    }

    /**
     * @param Closure(DeadReplicaCache): void        $mark         Mark operations to perform on the cache before assertions
     * @param list<array{string, int, string, bool}> $expectations Each entry is [host, port, pool, expected isDead]
     */
    #[DataProvider('markScenarioProvider')]
    public function testIsDeadReflectsMarkScenario(Closure $mark, array $expectations): void
    {
        $cache = new ApcuDeadReplicaCache();
        $mark($cache);

        foreach ($expectations as [$host, $port, $pool, $expected]) {
            $this->assertSame(
                $expected,
                $cache->isDead($host, $port, $pool),
                'isDead(' . $host . ', ' . $port . ', ' . $pool . ')',
            );
        }
    }

    /**
     * @return array<string, array{Closure(DeadReplicaCache): void, list<array{string, int, string, bool}>}>
     */
    public static function markScenarioProvider(): array
    {
        return [
            'no mark means not dead' => [
                static fn (DeadReplicaCache $cache) => null,
                [
                    ['r1.example', 3306, 'mydb', false],
                ],
            ],
            'server mark affects every pool on same host:port' => [
                static fn (DeadReplicaCache $cache) => $cache->markServerDead('r1.example', 3306, 60),
                [
                    ['r1.example', 3306, 'mydb', true],
                    ['r1.example', 3306, 'analytics', true],
                ],
            ],
            'pool mark affects only the same pool' => [
                static fn (DeadReplicaCache $cache) => $cache->markPoolDead('r1.example', 3306, 'mydb', 60),
                [
                    ['r1.example', 3306, 'mydb', true],
                    ['r1.example', 3306, 'analytics', false],
                ],
            ],
            'server mark does not affect different host or port' => [
                static fn (DeadReplicaCache $cache) => $cache->markServerDead('r1.example', 3306, 60),
                [
                    ['r2.example', 3306, 'mydb', false],
                    ['r1.example', 3307, 'mydb', false],
                ],
            ],
            'server and pool mark coexist on same target' => [
                static function (DeadReplicaCache $cache): void {
                    $cache->markServerDead('r1.example', 3306, 60);
                    $cache->markPoolDead('r1.example', 3306, 'mydb', 30);
                },
                [
                    ['r1.example', 3306, 'mydb', true],
                    ['r1.example', 3306, 'other', true],
                ],
            ],
        ];
    }

    public function testStoredValueIsTheClockTimestamp(): void
    {
        $cache = new ApcuDeadReplicaCache(static fn (): int => 1700000000);
        $cache->markServerDead('r1.example', 3306, 60);

        $this->assertSame(1700000000, apcu_fetch(DeadReplicaCacheKeys::server('r1.example', 3306)));
    }

    public function testStoredPoolValueIsTheClockTimestamp(): void
    {
        $cache = new ApcuDeadReplicaCache(static fn (): int => 1700000000);
        $cache->markPoolDead('r1.example', 3306, 'mydb', 60);

        $this->assertSame(1700000000, apcu_fetch(DeadReplicaCacheKeys::pool('r1.example', 3306, 'mydb')));
    }
}
