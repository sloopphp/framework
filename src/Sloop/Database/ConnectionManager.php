<?php

declare(strict_types=1);

namespace Sloop\Database;

use Psr\Log\LoggerInterface;
use Sloop\Database\Config\ConnectionConfigResolver;
use Sloop\Database\Config\PoolConfig;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Factory\ConnectionFactory;
use Sloop\Database\Replica\DeadReplicaCache;
use Sloop\Database\Replica\ReplicaSelectorRegistry;

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
 *
 * probeReplicas() complements the per-request lazy detection by actively
 * connecting to every replica and refreshing the dead cache for failures.
 * Healthy probes never clear existing dead marks (recovery is bound by the
 * cache TTL), so it is best run from cron to warm the negative cache ahead
 * of request traffic rather than as a recovery mechanism.
 */
final class ConnectionManager
{
    /**
     * Driver default port, used to normalize dead-cache keys when the config
     * omits `port`. Without this, `host` and `host + port: 3306` would track
     * the same physical server under two different keys.
     *
     * @var int
     */
    private const int DEFAULT_MYSQL_PORT = 3306;

    /**
     * Driver error codes that indicate a pool-specific failure (in addition
     * to SQLSTATE 28000 auth failures): the pool's user lacks access to its
     * database (1044) or the configured database does not exist (1049).
     * Both report SQLSTATE 42000, not 28000.
     *
     * @var list<int>
     */
    private const array POOL_SPECIFIC_ERROR_CODES = [1044, 1049];

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
     * @param string                              $defaultName      Pool name to return from connection()
     * @param array<string, array<string, mixed>> $configs          Pool configurations indexed by pool name
     * @param ConnectionFactory                   $factory          Builds Connection instances from validated configs
     * @param ReplicaSelectorRegistry             $replicaSelectors Maps each pool's `replica_selector` identifier to its strategy
     * @param DeadReplicaCache                    $deadCache        Negative cache for replicas that recently failed to connect
     * @param LoggerInterface|null                $logger           PSR-3 logger injected into each created Connection (typically the `database` channel); null disables query logging
     */
    public function __construct(
        private readonly string $defaultName,
        private readonly array $configs,
        private readonly ConnectionFactory $factory,
        private readonly ReplicaSelectorRegistry $replicaSelectors,
        private readonly DeadReplicaCache $deadCache,
        private readonly ?LoggerInterface $logger = null,
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
     * @throws DatabaseException           When a persistent primary carries a residual transaction that cannot be rolled back
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
     * @throws DatabaseException           When a persistent connection carries a residual transaction that cannot be rolled back
     */
    private function getPrimaryConnection(string $name): Connection
    {
        if (!isset($this->primaryConnections[$name])) {
            $pool = $this->resolvePool($name);

            $connection = $this->factory->make($pool->primary, $name, $pool->persistent);
            $this->applyLogger($connection, $pool);
            $this->applyQueryTimeout($connection, $pool);
            $this->recoverResidualTransaction($connection, $pool);
            $this->primaryConnections[$name] = $connection;
        }

        return $this->primaryConnections[$name];
    }

    /**
     * Return the cached replica Connection, or run the selection loop on first access.
     *
     * Selection flow:
     * 1. Filter out replicas marked dead in the cache
     * 2. ReplicaSelector picks one of the survivors
     * 3. Connect via ConnectionFactory; if health_check is on, verify with
     *    Connection::ping(); on persistent pools, roll back a residual transaction
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
            $index  = $this->replicaSelectors->get($pool->replicaSelector)->pick($candidates);
            $picked = $candidates[$index];
            $attempts++;

            try {
                $connection = $this->factory->make($picked, $name, $pool->persistent);
                $this->applyLogger($connection, $pool);
                $this->applyQueryTimeout($connection, $pool);

                if ($pool->healthCheck) {
                    $connection->ping();
                }

                $this->recoverResidualTransaction($connection, $pool);

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
     * Actively connect to every replica in a pool and report each result.
     *
     * Each replica is built via the ConnectionFactory and verified with
     * Connection::ping(); failures at either step are recorded in the dead
     * cache identically to a request-time miss (auth → markPoolDead, anything
     * else → markServerDead). Existing dead marks are not cleared on a healthy
     * probe — recovery still depends on the cache TTL — and the dead-cache
     * filter is intentionally bypassed so operators can see when a previously
     * marked replica has recovered. Probe connections are not cached on the
     * manager. Note that a replica connection already cached by a previous
     * connection(writable: false) call is NOT evicted by a failed probe: the
     * manager keeps serving it for the rest of the request/process.
     *
     * @param  string|null            $name Pool to probe; defaults to the configured default pool
     * @return array<string, bool>    host:port → true (probe succeeded) | false (probe failed)
     * @throws InvalidConfigException When the pool is undefined or its config is malformed
     */
    public function probeReplicas(?string $name = null): array
    {
        $poolName = $name ?? $this->defaultName;
        $pool     = $this->resolvePool($poolName);
        $results  = [];

        foreach ($pool->replicas as $replica) {
            $key = $replica->host . ':' . ($replica->port ?? self::DEFAULT_MYSQL_PORT);

            try {
                // Probe connections are short-lived and not reused across requests, so they
                // never use ATTR_PERSISTENT — opening one would just leak a slot in the
                // server-side persistent pool. Same rationale as skipping applyLogger /
                // applyQueryTimeout below.
                $connection = $this->factory->make($replica, $poolName, false);
                $connection->ping();
                $results[$key] = true;
            } catch (DatabaseException $e) {
                $this->recordReplicaFailure($pool, $replica, $e);
                $results[$key] = false;
            }
        }

        return $results;
    }

    /**
     * Wire the configured logger and pool-derived LoggingOptions into a fresh Connection.
     *
     * Skipped when no logger was provided to the manager, so test-only usage
     * stays untouched. Probe connections built by probeReplicas() are
     * intentionally not routed through this helper because they are transient
     * and never serve queries.
     *
     * @param  Connection $connection Newly built Connection that has not yet been cached
     * @param  PoolConfig $pool       Pool config providing the logging behavior settings
     * @return void
     */
    private function applyLogger(Connection $connection, PoolConfig $pool): void
    {
        if ($this->logger === null) {
            return;
        }

        $connection->setLogger(
            $this->logger,
            new LoggingOptions(
                logBindings:          $pool->logBindings,
                logAllQueries:        $pool->logAllQueries,
                slowQueryThresholdMs: $pool->slowQueryThresholdMs,
            ),
        );
    }

    /**
     * Forward the pool's query timeout into the Connection so it can lazy-apply on the first query.
     *
     * Skipped when the pool does not configure a timeout. Probe connections
     * built by probeReplicas() are intentionally bypassed: they only run a
     * `DO 1` ping and would just burn an extra round trip on a dialect probe
     * plus SET SESSION before being discarded.
     *
     * @param  Connection $connection Newly built Connection that has not yet executed a query
     * @param  PoolConfig $pool       Pool config supplying queryTimeoutMs
     * @return void
     */
    private function applyQueryTimeout(Connection $connection, PoolConfig $pool): void
    {
        if ($pool->queryTimeoutMs === null) {
            return;
        }

        $connection->setQueryTimeoutMs($pool->queryTimeoutMs);
    }

    /**
     * Roll back a transaction left open on a reused persistent connection.
     *
     * PDO::ATTR_PERSISTENT hands the same server session to the next request,
     * and PDO performs no cleanup of its own when the handle returns to the
     * pool: the PHP manual lists transactions and locks among the stateful
     * changes that may survive. A request that ended mid-transaction would
     * therefore leak its open transaction — and the rows it locked — into
     * whichever request picks the handle up next. Rolling back at acquisition
     * restores a clean session before the caller issues its first query.
     *
     * Non-persistent pools return immediately: a freshly opened session cannot
     * carry a transaction, so the check costs nothing there.
     *
     * The check cannot see a session that merely left `autocommit` disabled.
     * At acquisition time no statement has run yet, so the server has not
     * opened its implicit transaction and inTransaction() still reports false.
     * That class of leak is a documented limitation of persistent pools rather
     * than something this method can repair.
     *
     * @param  Connection        $connection Newly built Connection that has not yet been cached
     * @param  PoolConfig        $pool       Pool config providing the persistent flag and pool name
     * @return void
     * @throws DatabaseException When the rollback itself fails, leaving the session unusable
     */
    private function recoverResidualTransaction(Connection $connection, PoolConfig $pool): void
    {
        if (!$pool->persistent || !$connection->inTransaction()) {
            return;
        }

        $connection->rollback();

        $this->logger?->warning(
            'residual transaction detected on persistent connection; rolled back',
            ['connection_name' => $pool->name],
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

        $pool = ConnectionConfigResolver::validatePool($name, $this->configs[$name]);

        // Fail before the read path is entered. Which identifiers are valid is
        // decided by the registry's contents, so config validation only checks
        // that the value is a string.
        $this->replicaSelectors->get($pool->replicaSelector);

        return $pool;
    }

    /**
     * Partition replicas into alive candidates and dead-cache skip messages.
     *
     * Skipped entries are recorded as preformatted error strings so they can be
     * appended to the cumulative diagnostic message that surfaces when every
     * connection attempt (replica + primary fallback) fails.
     *
     * @param  PoolConfig                                       $pool Pool config (provides replica list and pool name)
     * @return array{0: list<ValidatedConfig>, 1: list<string>} [alive in declaration order, skip messages]
     */
    private function partitionByDeadCache(PoolConfig $pool): array
    {
        $alive   = [];
        $skipped = [];
        foreach ($pool->replicas as $replica) {
            if ($this->deadCache->isDead($replica->host, $replica->port ?? self::DEFAULT_MYSQL_PORT, $pool->name)) {
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
     * Pool-specific failures — auth errors (SQLSTATE 28000) and per-database
     * access errors (driver codes 1044 / 1049) — only affect this pool's
     * credentials or database, so the per-pool key is used. Everything else
     * (TCP refused, server unreachable, DO 1 failure) implies the host itself
     * is unhealthy and goes to the shared key.
     *
     * @param  PoolConfig        $pool    Pool config (provides pool name and TTL)
     * @param  ValidatedConfig   $replica The replica that just failed
     * @param  DatabaseException $e       Failure thrown by the factory or ping()
     * @return void
     */
    private function recordReplicaFailure(PoolConfig $pool, ValidatedConfig $replica, DatabaseException $e): void
    {
        $port = $replica->port ?? self::DEFAULT_MYSQL_PORT;

        $poolSpecific = $e instanceof DatabaseConnectionException
            && ($e->sqlState === '28000' || \in_array($e->driverCode, self::POOL_SPECIFIC_ERROR_CODES, true));

        if ($poolSpecific) {
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
