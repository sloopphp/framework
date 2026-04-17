<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

/**
 * Thrown on deadlock detection.
 *
 * SQLSTATE 40001 / MySQL error code 1213.
 * Typically retryable — used by Connection::transaction() for automatic retry.
 */
class DeadlockException extends QueryException
{
}
