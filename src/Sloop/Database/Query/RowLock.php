<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * How a SELECT holds the rows it reads.
 *
 * A locking read only means anything inside a transaction: outside one the
 * statement commits as it finishes and the lock goes with it.
 *
 * The cases say what to hold and what to do about a row someone else already
 * holds, without saying how to spell it. MySQL and MariaDB disagree on the
 * spelling of the shared case, and a Grammar for another dialect would disagree
 * further, so the SQL is written in compileLock() rather than carried here.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
enum RowLock
{
    /**
     * Hold every row read against both reading and writing by anyone else,
     * waiting for whoever holds it now.
     */
    case Update;

    /**
     * As Update, but leave out the rows someone else already holds instead of
     * waiting for them. Reads a different set of rows on each call, which is
     * what makes it useful for handing work to competing workers.
     */
    case UpdateSkipLocked;

    /**
     * As Update, but fail instead of waiting when a row is already held.
     *
     * Which exception that failure arrives as differs by server, and neither
     * server distinguishes it from an unrelated wait on the table's metadata.
     * Connection::query() maps MySQL's code 3572 to LockNotAvailableException;
     * MariaDB has no code of its own for this and reports code 1205, which
     * arrives as LockWaitTimeoutException and so is retried by
     * Connection::transaction().
     *
     * Select::count() refuses this case outright, because MySQL answers
     * COUNT(*) with 0 rather than failing when a row is held.
     */
    case UpdateNoWait;

    /**
     * Hold every row read against writing by anyone else, while letting others
     * read and take the same lock.
     */
    case Shared;
}
