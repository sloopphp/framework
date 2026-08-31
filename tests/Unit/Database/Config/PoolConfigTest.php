<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Config;

use PHPUnit\Framework\TestCase;
use Sloop\Database\CastMode;
use Sloop\Database\Config\PoolConfig;
use Sloop\Database\Config\ValidatedConfig;

final class PoolConfigTest extends TestCase
{
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
            logBindings: false,
            logAllQueries: true,
            slowQueryThresholdMs: 200,
            queryTimeoutMs: 5000,
            persistent: true,
            prefix: 'shop_',
            casts: CastMode::Datetime,
            strictMode: false,
        );

        $this->assertSame('mydb', $pool->name);
        $this->assertSame($primary, $pool->primary);
        $this->assertSame([$replica1, $replica2], $pool->replicas);
        $this->assertTrue($pool->healthCheck);
        $this->assertSame(300, $pool->deadCacheTtlSeconds);
        $this->assertSame('random', $pool->replicaSelector);
        $this->assertSame(3, $pool->maxConnectionAttempts);
        $this->assertFalse($pool->logBindings);
        $this->assertTrue($pool->logAllQueries);
        $this->assertSame(200, $pool->slowQueryThresholdMs);
        $this->assertSame(5000, $pool->queryTimeoutMs);
        $this->assertTrue($pool->persistent);
        $this->assertSame('shop_', $pool->prefix);
        $this->assertSame(CastMode::Datetime, $pool->casts);
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
            logBindings: true,
            logAllQueries: false,
            slowQueryThresholdMs: null,
            queryTimeoutMs: null,
            persistent: false,
            prefix: '',
            casts: CastMode::Off,
            strictMode: false,
        );

        $this->assertSame([], $pool->replicas);
        $this->assertFalse($pool->healthCheck);
        $this->assertSame(60, $pool->deadCacheTtlSeconds);
        $this->assertSame(1, $pool->maxConnectionAttempts);
        $this->assertTrue($pool->logBindings);
        $this->assertFalse($pool->logAllQueries);
        $this->assertNull($pool->slowQueryThresholdMs);
        $this->assertNull($pool->queryTimeoutMs);
        $this->assertFalse($pool->persistent);
        $this->assertSame('', $pool->prefix);
        $this->assertSame(CastMode::Off, $pool->casts);
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
            logBindings: true,
            logAllQueries: false,
            slowQueryThresholdMs: null,
            queryTimeoutMs: null,
            persistent: false,
            prefix: '',
            casts: CastMode::Off,
            strictMode: false,
        );

        $this->assertCount(1, $pool->replicas);
        $this->assertSame($replica, $pool->replicas[0]);
    }
}
