<?php

declare(strict_types=1);

namespace Sloop\Database\Factory;

use PDO;
use Sloop\Database\Config\ConnectionConfigResolver;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DatabaseConnectionException;

/**
 * Default ConnectionFactory: opens a real PDO connection via Connection::open().
 *
 * Resolves DSN and PDO options from the ValidatedConfig using
 * ConnectionConfigResolver, then delegates to Connection::open() for
 * the actual PDO instantiation. Production code path; tests typically
 * substitute a fake ConnectionFactory in the container instead.
 */
final class PdoConnectionFactory implements ConnectionFactory
{
    /**
     * Build a Connection by instantiating PDO from the validated config.
     *
     * @param  ValidatedConfig             $config     Validated single-connection config
     * @param  string                      $name       Pool name used as the Connection identifier in error context
     * @param  bool                        $persistent Whether to open the connection with PDO::ATTR_PERSISTENT
     * @return Connection
     * @throws DatabaseConnectionException When the underlying PDO connection fails
     */
    public function make(ValidatedConfig $config, string $name, bool $persistent): Connection
    {
        $options = ConnectionConfigResolver::resolvePdoOptions($config);
        if ($persistent) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        return Connection::open(
            ConnectionConfigResolver::resolveDsn($config),
            $config->username,
            $config->password,
            $options,
            $name,
        );
    }
}
