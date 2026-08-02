<?php

declare(strict_types=1);

namespace Sloop\Database;

/**
 * MySQL/MariaDB transaction isolation level.
 *
 * Used by Connection::begin() and Connection::transaction() to emit
 * `SET TRANSACTION ISOLATION LEVEL ...` before BEGIN. MySQL/MariaDB's
 * SET TRANSACTION applies only to the next single transaction, so the
 * session returns to the server default after commit/rollback.
 *
 * Default means "use the server default" and skips the SET TRANSACTION.
 */
enum IsolationLevel: string
{
    case Default         = 'DEFAULT';
    case ReadUncommitted = 'READ UNCOMMITTED';
    case ReadCommitted   = 'READ COMMITTED';
    case RepeatableRead  = 'REPEATABLE READ';
    case Serializable    = 'SERIALIZABLE';

    /**
     * Build the SQL statement that applies this isolation level.
     *
     * For Default this returns an empty string because the server default
     * is used and no SET TRANSACTION needs to be issued.
     *
     * @return string Full `SET TRANSACTION ISOLATION LEVEL ...` statement,
     *                or empty string for Default
     */
    public function toSqlStatement(): string
    {
        if ($this === self::Default) {
            return '';
        }

        return 'SET TRANSACTION ISOLATION LEVEL ' . $this->value;
    }
}
