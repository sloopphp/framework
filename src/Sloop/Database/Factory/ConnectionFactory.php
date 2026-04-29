<?php

declare(strict_types=1);

namespace Sloop\Database\Factory;

use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DatabaseConnectionException;

/**
 * Builds Connection instances from validated single-connection configs.
 *
 * Extracted from ConnectionManager so that the PDO instantiation step can
 * be replaced with a fake in unit tests, allowing failure paths
 * (TCP refused, auth error, server unreachable) to be exercised without
 * a real database server.
 */
interface ConnectionFactory
{
    /**
     * Build a Connection from a fully validated config.
     *
     * @param  ValidatedConfig             $config Validated single-connection config
     * @param  string                      $name   Pool name used as the Connection identifier in error context
     * @return Connection
     * @throws DatabaseConnectionException When the underlying PDO connection fails
     */
    public function make(ValidatedConfig $config, string $name): Connection;
}
