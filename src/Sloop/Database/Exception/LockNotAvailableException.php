<?php

declare(strict_types=1);

namespace Sloop\Database\Exception;

/**
 * Thrown when a lock cannot be acquired immediately.
 *
 * MySQL error code 3572 (FOR UPDATE NOWAIT / SKIP LOCKED failure).
 * Not automatically retried — the caller must decide the strategy.
 */
class LockNotAvailableException extends QueryException
{
}
