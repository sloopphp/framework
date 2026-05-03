<?php

declare(strict_types=1);

namespace Sloop\Database;

use Sloop\Database\Config\ConnectionConfigResolver;
use Sloop\Database\Config\PoolConfig;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Factory\ConnectionFactory;
use Sloop\Database\Replica\DeadReplicaCache;
use Sloop\Database\Replica\ReplicaSelector;

/**
 * Lazily creates and caches database connections from pool configurations.
 *
 * Each `connections.<name>` config entry is interpreted as a pool definition
 * (primary + optional replica list + pool-level behavior keys) via
 * ConnectionConfigResolver::validatePool(). Connections are built through
 * the injected ConnectionFactory the first time they are requested and
 * cached so a single request reuses one PDO instance per pool name.
 *
 * connection() routing:
 * - $writable === true  → primary
 * - $writable === false → replica (dead-cache filter → ReplicaSelector → ping
 *                                  → record on failure → next → primary fallback
 *                                  → throw on max_connection_attempts exhaustion)
 * - $writable === null  → primary (Builder layer detects SELECT, not the manager)
 *
 * Empty `read` list collapses replica routing to the primary so single-pool
 * setups keep working without reconfiguration.
 */
final class ConnectionManager
{
    /**
     * Cached primary Connection instances keyed by pool name.
     *
     * @var array<string, Connection>
     */
    private array $primaryConnections = [];

    /**
     * Cached replica Connection instances keyed by pool name.
     *
     * Replica selection runs at most once per pool per request: once a healthy
     * replica is found, subsequent connection(writable: false) calls reuse it.
     *
     * @var array<string, Connection>
     */
    private array $replicaConnections = [];

    /**
     * Construct a new ConnectionManager.
     *
     * @param string                              $defaultName     Pool name to return from connection()
     * @param array<string, array<string, mixed>> $configs         Pool configurations indexed by pool name
     * @param ConnectionFactory                   $factory         Builds Connection instances from validated configs
     * @param ReplicaSelector                     $replicaSelector Strategy for picking one replica from surviving candidates
     * @param DeadReplicaCache                    $deadCache       Negative cache for replicas that recently failed to connect
     */
    public function __construct(
        private readonly string $defaultName,
        private readonly array $configs,
        private readonly ConnectionFactory $factory,
        private readonly ReplicaSelector $replicaSelector,
        private readonly DeadReplicaCache $deadCache,
    ) {
    }

    /**
     * Return the default pool's connection, routing to primary or replica based on $writable.
     *
     * Once the cached primary Connection is in a transaction, every subsequent
     * call (regardless of $writable) returns that primary so that reads inside
     * a write transaction stay consistent with the in-flight changes. Routing
     * resumes the normal $writable rules after commit() / rollback() leaves
     * inTransaction() == false.
     *
     * @param  bool|null                   $writable true → primary; false → replica with primary fallback;
     *                                               null → primary (Builder layer is responsible for SELECT detection)
     * @return Connection                  Lazy-created, cached Connection
     * @throws InvalidConfigException      When the default pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When max_connection_attempts is exhausted on the replica path
     */
    public function connection(?bool $writable = null): Connection
    {
        if (isset($this->primaryConnections[$this->defaultName])
            && $this->primaryConnections[$this->defaultName]->inTransaction()) {
            return $this->primaryConnections[$this->defaultName];
        }

        if ($writable === false) {
            return $this->getReplicaConnection($this->defaultName);
        }

        return $this->getPrimaryConnection($this->defaultName);
    }

    /**
     * Return the cached primary Connection or build it on first access.
     *
     * @param  string                      $name Pool name
     * @return Connection
     * @throws InvalidConfigException      When the name is undefined or config is malformed
     * @throws DatabaseConnectionException When the underlying PDO connection fails
     */
    private function getPrimaryConnection(string $name): Connection
    {
        if (!isset($this->primaryConnections[$name])) {
            $pool = $this->resolvePool($name);

            $this->primaryConnections[$name] = $this->factory->make($pool->primary, $name);
        }

        return $this->primaryConnections[$name];
    }

    /**
     * Return the cached replica Connection, or run the selection loop on first access.
     *
     * Selection flow:
     * 1. Filter out replicas marked dead in the cache
     * 2. ReplicaSelector picks one of the survivors
     * 3. Connect via ConnectionFactory; if health_check is on, verify with Connection::ping()
     * 4. On failure: record dead (auth → markPoolDead, otherwise markServerDead)
     *    and remove the candidate; loop until success, candidates exhausted,
     *    or max_connection_attempts reached
     * 5. When all replicas fail and attempts remain, fall back to primary
     * 6. If no healthy connection is found, throw DatabaseConnectionException
     *    with cumulative per-attempt error details
     *
     * @param  string                      $name Pool name
     * @return Connection
     * @throws InvalidConfigException      When the name is undefined or config is malformed
     * @throws DatabaseConnectionException When all attempts (replica + fallback) fail
     */
    private function getReplicaConnection(string $name): Connection
    {
        if (isset($this->replicaConnections[$name])) {
            return $this->replicaConnections[$name];
        }

        $pool = $this->resolvePool($name);

        if ($pool->replicas === []) {
            // No replicas configured → primary doubles as the read endpoint.
            return $this->getPrimaryConnection($name);
        }

        [$candidates, $errors] = $this->partitionByDeadCache($pool);
        $attempts              = 0;

        while ($candidates !== [] && $attempts < $pool->maxConnectionAttempts) {
            $index  = $this->replicaSelector->pick($candidates);
            $picked = $candidates[$index];
            $attempts++;

            try {
                $connection = $this->factory->make($picked, $name);

                if ($pool->healthCheck) {
                    $connection->ping();
                }

                $this->replicaConnections[$name] = $connection;

                return $connection;
            } catch (DatabaseException $e) {
                $errors[] = $this->formatAttemptError($picked, $e);
                $this->recordReplicaFailure($pool, $picked, $e);
                array_splice($candidates, $index, 1);
            }
        }

        if ($attempts < $pool->maxConnectionAttempts) {
            try {
                return $this->getPrimaryConnection($name);
            } catch (DatabaseException $e) {
                $errors[] = $this->formatAttemptError($pool->primary, $e);
            }
        }

        throw new DatabaseConnectionException(
            'Failed to obtain a read connection for pool [' . $name . '] (replica + primary fallback exhausted): ' . implode(' | ', $errors),
            $name,
        );
    }

    /**
     * Resolve and validate the pool config for the given name.
     *
     * @param  string                 $name Pool name
     * @return PoolConfig
     * @throws InvalidConfigException When the name is undefined or config is malformed
     */
    private function resolvePool(string $name): PoolConfig
    {
        if (!\array_key_exists($name, $this->configs)) {
            throw new InvalidConfigException(
                'Database connection [' . $name . '] is not defined.',
            );
        }

        return ConnectionConfigResolver::validatePool($name, $this->configs[$name]);
    }

    /**
     * Partition replicas into alive candidates and dead-cache skip messages.
     *
     * Skipped entries are recorded as preformatted error strings so they can be
     * appended to the cumulative diagnostic message that surfaces when every
     * connection attempt (replica + primary fallback) fails.
     *
     * @param  PoolConfig                                       $pool Pool config (provides replica list and pool name)
     * @return array{0: list<ValidatedConfig>, 1: list<string>}       [alive in declaration order, skip messages]
     */
    private function partitionByDeadCache(PoolConfig $pool): array
    {
        $alive   = [];
        $skipped = [];
        foreach ($pool->replicas as $replica) {
            if ($this->deadCache->isDead($replica->host, $replica->port ?? 0, $pool->name)) {
                $skipped[] = $replica->host . ':' . ($replica->port ?? '?') . ' → skipped (dead-cache)';

                continue;
            }
            $alive[] = $replica;
        }

        return [$alive, $skipped];
    }

    /**
     * Mark a failed replica dead in the negative cache.
     *
     * Auth failures (SQLSTATE 28000) are pool-specific: the credential is wrong
     * only for this pool, so the per-pool key is used. Everything else (TCP
     * refused, server unreachable, DO 1 failure) implies the host itself is
     * unhealthy and goes to the shared key.
     *
     * @param  PoolConfig        $pool    Pool config (provides pool name and TTL)
     * @param  ValidatedConfig   $replica The replica that just failed
     * @param  DatabaseException $e       Failure thrown by the factory or ping()
     * @return void
     */
    private function recordReplicaFailure(PoolConfig $pool, ValidatedConfig $replica, DatabaseException $e): void
    {
        $port = $replica->port ?? 0;

        if ($e instanceof DatabaseConnectionException && $e->sqlState === '28000') {
            $this->deadCache->markPoolDead($replica->host, $port, $pool->name, $pool->deadCacheTtlSeconds);

            return;
        }

        $this->deadCache->markServerDead($replica->host, $port, $pool->deadCacheTtlSeconds);
    }

    /**
     * Format one failed attempt for the cumulative error message.
     *
     * @param  ValidatedConfig   $config Target host/port
     * @param  DatabaseException $e      Failure
     * @return string
     */
    private function formatAttemptError(ValidatedConfig $config, DatabaseException $e): string
    {
        return $config->host . ':' . ($config->port ?? '?') . ' → ' . $e->getMessage();
    }
}
