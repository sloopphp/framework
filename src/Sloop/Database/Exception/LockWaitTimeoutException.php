<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

/**
 * Thrown when a lock wait times out.
 *
 * MySQL error code 1205 (innodb_lock_wait_timeout exceeded).
 * Retryable — used by Connection::transaction() for automatic retry alongside DeadlockException.
 */
final class LockWaitTimeoutException extends QueryException
{
}
