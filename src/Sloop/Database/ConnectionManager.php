<?php

declare(strict_types=1);

namespace Sloop\Database;

use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * Lazily creates and caches database connections from configuration.
 *
 * Connections are built via Connection::open() the first time they
 * are requested. Subsequent calls return the cached Connection so
 * that a single request reuses one PDO instance per connection name.
 *
 * Sub B exposes only the default connection through connection().
 * Sub C extends the API with a writable parameter to drive
 * master/replica routing (`connection(?bool $writable = null)`).
 */
final class ConnectionManager
{
    /**
     * Cached Connection instances keyed by connection name.
     *
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * Construct a new ConnectionManager.
     *
     * @param string                              $defaultName Connection name to return from connection()
     * @param array<string, array<string, mixed>> $configs     Connection configurations indexed by connection name
     */
    public function __construct(
        private readonly string $defaultName,
        private readonly array $configs,
    ) {
    }

    /**
     * Return the default connection, creating and caching it on first call.
     *
     * @return Connection                Lazy-created, cached Connection
     * @throws InvalidConfigException    When the default connection name is not defined or its config is malformed
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
     * Build a new Connection from the named config entry.
     *
     * @param  string                      $name Connection name
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

        $validated = ConnectionConfigResolver::validate($name, $this->configs[$name]);

        return Connection::open(
            ConnectionConfigResolver::resolveDsn($validated),
            $validated->username,
            $validated->password,
            ConnectionConfigResolver::resolvePdoOptions($validated),
            $name,
        );
    }
}
