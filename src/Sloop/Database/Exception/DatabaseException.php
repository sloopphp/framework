<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

use Sloop\Error\SloopException;
use Throwable;

/**
 * Base exception for all database-related errors.
 *
 * Carries connection name, SQLSTATE code, and driver-specific error code
 * so that exception handlers can classify and log consistently.
 */
class DatabaseException extends SloopException
{
    /**
     * Name of the database connection that caused the error.
     *
     * @var string
     */
    public protected(set) string $connectionName = '';

    /**
     * SQLSTATE error code from PDO (e.g. "42000", "23000").
     *
     * @var string|null
     */
    public protected(set) ?string $sqlState = null;

    /**
     * Driver-specific error code (e.g. MySQL 1062, 1213).
     *
     * @var int|null
     */
    public protected(set) ?int $driverCode = null;

    /**
     * Create a new database exception.
     *
     * @param string         $message        Error message
     * @param string         $connectionName Connection name
     * @param string|null    $sqlState       SQLSTATE code
     * @param int|null       $driverCode     Driver error code
     * @param Throwable|null $previous       Previous exception for chaining
     * @return void
     */
    public function __construct(
        string $message = '',
        string $connectionName = '',
        ?string $sqlState = null,
        ?int $driverCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);

        $this->connectionName = $connectionName;
        $this->sqlState       = $sqlState;
        $this->driverCode     = $driverCode;
    }
}
