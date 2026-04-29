<?php

declare(strict_types=1);

namespace Sloop\Database\Replica;

/**
 * Cache key formatting for DeadReplicaCache implementations.
 *
 * Both ApcuDeadReplicaCache and InMemoryDeadReplicaCache must agree on the
 * exact key format so operators inspecting the cache (or tools surfacing
 * APCu contents) see consistent names regardless of which backend is active.
 * Centralizing the format here eliminates the risk of divergence when the
 * format is changed.
 *
 * @internal Used by DeadReplicaCache implementations and their tests.
 */
final class DeadReplicaCacheKeys
{
    /**
     * Build the shared server-level cache key.
     *
     * @param  string $host Replica host
     * @param  int    $port Replica port
     * @return string
     */
    public static function server(string $host, int $port): string
    {
        return 'sloop.db.dead.' . $host . ':' . $port;
    }

    /**
     * Build the pool-specific cache key.
     *
     * @param  string $host Replica host
     * @param  int    $port Replica port
     * @param  string $pool Pool name
     * @return string
     */
    public static function pool(string $host, int $port, string $pool): string
    {
        return 'sloop.db.dead.' . $host . ':' . $port . '.' . $pool;
    }
}
