<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Sloop\Database\PoolConfig;
use Sloop\Database\ValidatedConfig;

final class PoolConfigTest extends TestCase
{
    public function testStoresAllFields(): void
    {
        $primary  = $this->makeValidatedConfig('primary.example.com');
        $replica1 = $this->makeValidatedConfig('replica-1.example.com');
        $replica2 = $this->makeValidatedConfig('replica-2.example.com');

        $pool = new PoolConfig(
            name: 'mydb',
            primary: $primary,
            replicas: [$replica1, $replica2],
            healthCheck: true,
            deadCacheTtlSeconds: 300,
            replicaSelector: 'random',
            maxConnectionAttempts: 3,
        );

        $this->assertSame('mydb', $pool->name);
        $this->assertSame($primary, $pool->primary);
        $this->assertSame([$replica1, $replica2], $pool->replicas);
        $this->assertTrue($pool->healthCheck);
        $this->assertSame(300, $pool->deadCacheTtlSeconds);
        $this->assertSame('random', $pool->replicaSelector);
        $this->assertSame(3, $pool->maxConnectionAttempts);
    }

    public function testStoresEmptyReplicas(): void
    {
        $pool = new PoolConfig(
            name: 'mydb',
            primary: $this->makeValidatedConfig('primary.example.com'),
            replicas: [],
            healthCheck: false,
            deadCacheTtlSeconds: 60,
            replicaSelector: 'random',
            maxConnectionAttempts: 1,
        );

        $this->assertSame([], $pool->replicas);
        $this->assertFalse($pool->healthCheck);
        $this->assertSame(60, $pool->deadCacheTtlSeconds);
        $this->assertSame(1, $pool->maxConnectionAttempts);
    }

    public function testStoresSingleReplica(): void
    {
        $primary = $this->makeValidatedConfig('primary.example.com');
        $replica = $this->makeValidatedConfig('replica.example.com');

        $pool = new PoolConfig(
            name: 'mydb',
            primary: $primary,
            replicas: [$replica],
            healthCheck: true,
            deadCacheTtlSeconds: 300,
            replicaSelector: 'random',
            maxConnectionAttempts: 2,
        );

        $this->assertCount(1, $pool->replicas);
        $this->assertSame($replica, $pool->replicas[0]);
    }

    private function makeValidatedConfig(string $host): ValidatedConfig
    {
        return new ValidatedConfig(
            driver: 'mysql',
            host: $host,
            port: null,
            database: 'app',
            username: 'user',
            password: 'pass',
            charset: null,
            collation: null,
            connectTimeoutSeconds: null,
            options: [],
        );
    }
}
