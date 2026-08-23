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
     * Which exception that failure arrives as differs by server. MySQL keeps
     * this failure apart from an unrelated wait on the table's metadata, 3572
     * against 1205; MariaDB answers 1205 to both and so cannot.
     * Connection::query() maps MySQL's code 3572 to LockNotAvailableException;
     * MariaDB has no code of its own for this and reports code 1205, which
     * arrives as LockWaitTimeoutException and so is retried by
     * Connection::transaction().
     *
     * Select::count() refuses this case outright; the reason is set out there
     * rather than repeated here.
     */
    case UpdateNoWait;

    /**
     * Hold every row read against writing by anyone else, while letting others
     * read and take the same lock.
     */
    case Shared;
}
