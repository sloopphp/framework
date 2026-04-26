<?php

declare(strict_types=1);

namespace Sloop\Database;

use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * Lazily creates and caches database connections from pool configurations.
 *
 * Each `connections.<name>` config entry is interpreted as a pool definition
 * (primary + optional replica list + pool-level behavior keys) via
 * ConnectionConfigResolver::validatePool(). Connections are built through
 * the injected ConnectionFactory the first time they are requested and
 * cached so a single request reuses one PDO instance per pool name.
 *
 * connection() currently returns the pool's primary; replica selection and
 * the writable parameter are not yet implemented.
 */
final class ConnectionManager
{
    /**
     * Cached Connection instances keyed by pool name.
     *
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * Construct a new ConnectionManager.
     *
     * @param string                              $defaultName Pool name to return from connection()
     * @param array<string, array<string, mixed>> $configs     Pool configurations indexed by pool name
     * @param ConnectionFactory                   $factory     Builds Connection instances from validated configs
     */
    public function __construct(
        private readonly string $defaultName,
        private readonly array $configs,
        private readonly ConnectionFactory $factory,
    ) {
    }

    /**
     * Return the default pool's primary connection, creating and caching it on first call.
     *
     * @return Connection                  Lazy-created, cached Connection to the default pool's primary
     * @throws InvalidConfigException      When the default pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the underlying PDO connection cannot be established
     */
    public function connection(): Connection
    {
        if (!isset($this->connections[$this->defaultName])) {
            $this->connections[$this->defaultName] = $this->makeConnection($this->defaultName);
        }

        return $this->connections[$this->defaultName];
    }

    /**
     * Build a new Connection to the named pool's primary via the injected factory.
     *
     * @param  string                      $name Pool name
     * @return Connection
     * @throws InvalidConfigException      When the name is undefined or the config is malformed
     * @throws DatabaseConnectionException When the underlying PDO connection cannot be established
     */
    private function makeConnection(string $name): Connection
    {
        if (!\array_key_exists($name, $this->configs)) {
            throw new InvalidConfigException(
                'Database connection [' . $name . '] is not defined.',
            );
        }

        $pool = ConnectionConfigResolver::validatePool($name, $this->configs[$name]);

        return $this->factory->make($pool->primary, $name);
    }
}
