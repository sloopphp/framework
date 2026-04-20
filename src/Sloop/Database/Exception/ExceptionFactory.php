<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

use PDOException;

/**
 * Map PDOException instances to sloop-specific database exception classes.
 *
 * Classification is primarily based on the driver-specific error code
 * (`PDOException::$errorInfo[1]`). SQLSTATE (`[0]`) is used as a fallback
 * when the driver code is missing or not distinctive. This keeps Connection
 * and ConnectionManager free of classification noise.
 */
final class ExceptionFactory
{
    /**
     * Driver error codes that indicate a connection-level failure.
     *
     * @var list<int>
     */
    private const array CONNECTION_ERROR_CODES = [
        1044, // Access denied to database
        1045, // Access denied for user
        1049, // Unknown database
        2002, // Can't connect (socket/refused)
        2003, // Can't connect (TCP)
        2005, // Unknown host
        2006, // Server has gone away
        2013, // Lost connection during query
    ];

    /**
     * Driver error code to QueryException subclass mapping.
     *
     * @var array<int, class-string<QueryException>>
     */
    private const array DRIVER_CODE_MAP = [
        1213 => DeadlockException::class,                  // deadlock detected (SQLSTATE 40001)
        1205 => LockWaitTimeoutException::class,           // innodb_lock_wait_timeout exceeded
        3572 => LockNotAvailableException::class,          // FOR UPDATE NOWAIT / SKIP LOCKED failure
        1062 => UniqueConstraintViolationException::class, // duplicate key
        1451 => ForeignKeyViolationException::class,       // foreign key constraint (on delete/update)
        1452 => ForeignKeyViolationException::class,       // foreign key constraint (parent missing)
        1064 => SyntaxErrorException::class,               // SQL syntax error
    ];

    /**
     * Classify a PDOException and wrap it in the most specific sloop exception.
     *
     * @param  PDOException             $e              Original PDO exception
     * @param  string                   $connectionName Originating connection name (optional)
     * @param  string                   $sql            SQL statement that failed (empty for connect-time failures)
     * @param  array<int|string, mixed> $bindings       Parameter bindings for the failed statement
     * @return DatabaseException        Most specific DatabaseException subclass for the situation
     */
    public static function fromPDOException(
        PDOException $e,
        string $connectionName = '',
        string $sql = '',
        array $bindings = [],
    ): DatabaseException {
        $errorInfo  = $e->errorInfo ?? null;
        $sqlState   = isset($errorInfo[0]) && \is_string($errorInfo[0]) ? $errorInfo[0] : null;
        $driverCode = isset($errorInfo[1]) && \is_int($errorInfo[1]) ? $errorInfo[1] : null;
        $message    = $e->getMessage();

        if ($driverCode !== null && \in_array($driverCode, self::CONNECTION_ERROR_CODES, true)) {
            return new DatabaseConnectionException($message, $connectionName, $sqlState, $driverCode, $e);
        }

        if ($driverCode !== null && isset(self::DRIVER_CODE_MAP[$driverCode])) {
            $class = self::DRIVER_CODE_MAP[$driverCode];

            return new $class($message, $sql, $bindings, $connectionName, $sqlState, $driverCode, $e);
        }

        return self::classifyBySqlState($e, $message, $sql, $bindings, $connectionName, $sqlState, $driverCode);
    }

    /**
     * Fallback classification by SQLSTATE when the driver code is not decisive.
     *
     * @param  PDOException             $e              Original PDO exception
     * @param  string                   $message        Human-readable message from $e
     * @param  string                   $sql            SQL that failed
     * @param  array<int|string, mixed> $bindings       Bindings for the failed statement
     * @param  string                   $connectionName Originating connection name
     * @param  string|null              $sqlState       SQLSTATE extracted from $e
     * @param  int|null                 $driverCode     Driver code extracted from $e
     * @return DatabaseException        Most specific subclass inferable from SQLSTATE
     */
    private static function classifyBySqlState(
        PDOException $e,
        string $message,
        string $sql,
        array $bindings,
        string $connectionName,
        ?string $sqlState,
        ?int $driverCode,
    ): DatabaseException {
        if ($sqlState === null) {
            return new QueryException($message, $sql, $bindings, $connectionName, $sqlState, $driverCode, $e);
        }

        if (str_starts_with($sqlState, '23')) {
            return new ConstraintViolationException($message, $sql, $bindings, $connectionName, $sqlState, $driverCode, $e);
        }

        if (str_starts_with($sqlState, '42')) {
            return new SyntaxErrorException($message, $sql, $bindings, $connectionName, $sqlState, $driverCode, $e);
        }

        if (str_starts_with($sqlState, '08')) {
            return new DatabaseConnectionException($message, $connectionName, $sqlState, $driverCode, $e);
        }

        return new QueryException($message, $sql, $bindings, $connectionName, $sqlState, $driverCode, $e);
    }
}
