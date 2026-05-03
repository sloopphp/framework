<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

use LogicException;
use Sloop\Database\Config\ValidatedConfig;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Factory\ConnectionFactory;

/**
 * Test double that returns scripted responses keyed by "host:port".
 *
 * Each scripted entry is either a Connection (returned from make()) or a
 * DatabaseConnectionException (thrown from make()). The host:port the factory
 * was invoked against is recorded in $invocations for assertions about the
 * order of attempts.
 *
 * Use expectSuccess() to script a successful connect for one host, and
 * expectFailure() to script a failure (auth error, TCP refused, etc.).
 */
final class ScriptedConnectionFactory implements ConnectionFactory
{
    /**
     * Scripted responses keyed by "host:port" (port = 0 for null ports).
     *
     * @var array<string, Connection|DatabaseConnectionException>
     */
    private array $script = [];

    /**
     * Recorded invocations in call order, each as "host:port".
     *
     * @var list<string>
     */
    public array $invocations = [];

    /**
     * Script a successful connect for the given host:port.
     *
     * @param  string     $host       Replica or primary host the test references
     * @param  int        $port       Replica or primary port (use 0 to match ValidatedConfig with null port)
     * @param  Connection $connection Connection instance to return
     * @return void
     */
    public function expectSuccess(string $host, int $port, Connection $connection): void
    {
        $this->script[self::key($host, $port)] = $connection;
    }

    /**
     * Script a failure for the given host:port.
     *
     * @param  string                      $host      Replica or primary host the test references
     * @param  int                         $port      Replica or primary port (use 0 to match ValidatedConfig with null port)
     * @param  DatabaseConnectionException $exception Exception to throw from make()
     * @return void
     */
    public function expectFailure(string $host, int $port, DatabaseConnectionException $exception): void
    {
        $this->script[self::key($host, $port)] = $exception;
    }

    /**
     * Return or throw the scripted response for the configured host:port.
     *
     * @param  ValidatedConfig             $config Validated config the manager passes to the factory
     * @param  string                      $name   Pool name (unused; recorded by manager-level assertions)
     * @return Connection
     * @throws DatabaseConnectionException When the scripted response for $config is a failure
     * @throws LogicException              When no scripted response exists for $config (test setup error)
     */
    public function make(ValidatedConfig $config, string $name): Connection
    {
        $key                 = self::key($config->host, $config->port ?? 0);
        $this->invocations[] = $key;

        if (!\array_key_exists($key, $this->script)) {
            throw new LogicException(
                'ScriptedConnectionFactory: no scripted response for ' . $key,
            );
        }

        $response = $this->script[$key];
        if ($response instanceof DatabaseConnectionException) {
            throw $response;
        }

        return $response;
    }

    /**
     * Build the script lookup key.
     *
     * @param  string $host
     * @param  int    $port
     * @return string
     */
    private static function key(string $host, int $port): string
    {
        return $host . ':' . $port;
    }
}
