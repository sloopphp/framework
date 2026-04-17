<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

use Throwable;

/**
 * Thrown when a SQL query fails at execution time.
 *
 * Carries the SQL statement and bound parameters in addition to the
 * connection / SQLSTATE / driver code inherited from DatabaseException.
 */
class QueryException extends DatabaseException
{
    /**
     * The SQL statement that failed.
     *
     * @var string
     */
    public protected(set) string $sql = '';

    /**
     * Bound parameters for the failed query.
     *
     * @var array<int|string, mixed>
     */
    public protected(set) array $bindings = [];

    /**
     * Create a new query exception.
     *
     * @param string                   $message        Error message
     * @param string                   $sql            Failed SQL statement
     * @param array<int|string, mixed> $bindings       Bound parameters
     * @param string                   $connectionName Connection name
     * @param string|null              $sqlState       SQLSTATE code
     * @param int|null                 $driverCode     Driver error code
     * @param Throwable|null           $previous       Previous exception for chaining
     * @return void
     */
    public function __construct(
        string $message = '',
        string $sql = '',
        array $bindings = [],
        string $connectionName = '',
        ?string $sqlState = null,
        ?int $driverCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $connectionName, $sqlState, $driverCode, $previous);

        $this->sql      = $sql;
        $this->bindings = $bindings;
    }
}
