<?php

declare(strict_types=1);

namespace Sloop\Database\Replica;

use Closure;

/**
 * APCu-backed DeadReplicaCache shared across all requests on the same node.
 *
 * Cross-request sharing means that one request's failure to connect
 * propagates to the next request immediately, avoiding the per-worker
 * re-discovery cost that the InMemory fallback would impose. APCu manages
 * TTL natively so this implementation does not poll for expiry.
 *
 * Use {@see ApcuDeadReplicaCache::isAvailable()} to decide between this
 * and InMemoryDeadReplicaCache at bootstrap time; ext-apcu is declared
 * as a composer suggest, not a hard requirement.
 */
final class ApcuDeadReplicaCache implements DeadReplicaCache
{
    /**
     * Returns the current unix timestamp; injected for testability.
     *
     * Stored as the cache value (the dead mark timestamp) so operators
     * inspecting APCu can see when each entry was recorded.
     *
     * @var Closure(): int
     */
    private Closure $clock;

    /**
     * Build an APCu-backed cache. The clock controls the timestamp value stored on mark, not TTL evaluation; APCu manages expiry internally.
     *
     * @param (Closure(): int)|null $clock Returns current unix timestamp; defaults to time(...)
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Whether ext-apcu is loaded and APCu is enabled in the current SAPI.
     *
     * CLI requires `apc.enable_cli=1`; otherwise apcu_enabled() returns false
     * even when the extension is present. Bootstrap code should pick
     * InMemoryDeadReplicaCache when this returns false.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return \function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Cross-request lookup via APCu; TTL expiry is handled by the APCu engine.
     *
     * @param  string $host Replica host
     * @param  int    $port Replica port
     * @param  string $pool Pool name
     * @return bool
     *
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public function isDead(string $host, int $port, string $pool): bool
    {
        return apcu_exists(DeadReplicaCacheKeys::server($host, $port))
            || apcu_exists(DeadReplicaCacheKeys::pool($host, $port, $pool));
    }

    /**
     * Stores the marked-at timestamp in APCu under the shared key with the given TTL.
     *
     * @param  string $host       Replica host
     * @param  int    $port       Replica port
     * @param  int    $ttlSeconds How long the dead mark should remain
     * @return void
     *
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public function markServerDead(string $host, int $port, int $ttlSeconds): void
    {
        apcu_store(DeadReplicaCacheKeys::server($host, $port), ($this->clock)(), $ttlSeconds);
    }

    /**
     * Stores the marked-at timestamp in APCu under the pool-specific key with the given TTL.
     *
     * @param  string $host       Replica host
     * @param  int    $port       Replica port
     * @param  string $pool       Pool name
     * @param  int    $ttlSeconds How long the dead mark should remain
     * @return void
     *
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public function markPoolDead(string $host, int $port, string $pool, int $ttlSeconds): void
    {
        apcu_store(DeadReplicaCacheKeys::pool($host, $port, $pool), ($this->clock)(), $ttlSeconds);
    }
}
