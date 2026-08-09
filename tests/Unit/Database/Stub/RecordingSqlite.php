<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

use Pdo\Sqlite;
use PDOStatement;

/**
 * SQLite connection that records every call the driver receives.
 *
 * Connection's own query log only reports query() and statement(), and only
 * once per call: it is written after the work is done, so a method that sends
 * the same statement twice still logs one line. Transaction control, ping and
 * the version lookup bypass the logger entirely, reaching PDO directly or
 * through execSimple().
 *
 * Recording at the driver instead closes both gaps. Everything Connection asks
 * of the server passes through one of the methods below, so $calls is the full
 * list of what was sent, in order, including repeats.
 *
 * Statements are recorded before being forwarded, so a call that throws is
 * still recorded: the point is what was attempted.
 */
final class RecordingSqlite extends Sqlite
{
    /**
     * Every driver call in the order it was made.
     *
     * Statements are recorded as "<method>: <sql>", control calls as the bare
     * method name.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @param  string                  $query   Statement to prepare
     * @param  array<array-key, mixed> $options Driver options
     * @return PDOStatement|false      Prepared statement, or false on failure
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->calls[] = 'prepare: ' . $query;

        return parent::prepare($query, $options);
    }

    /**
     * @param  string    $statement Statement to execute
     * @return int|false Affected rows, or false on failure
     */
    public function exec(string $statement): int|false
    {
        $this->calls[] = 'exec: ' . $statement;

        return parent::exec($statement);
    }

    /**
     * @param  string             $query         Statement to run
     * @param  int|null           $fetchMode     Fetch mode, null to leave the default
     * @param  mixed              $fetchModeArgs Arguments belonging to the fetch mode
     * @return PDOStatement|false Result set, or false on failure
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->calls[] = 'query: ' . $query;

        // PDO treats "no fetch mode" and "fetch mode null" differently, so the
        // argument is only forwarded when the caller supplied one.
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    /**
     * @return bool True when the transaction was started
     */
    public function beginTransaction(): bool
    {
        $this->calls[] = 'beginTransaction';

        return parent::beginTransaction();
    }

    /**
     * @return bool True when the transaction was committed
     */
    public function commit(): bool
    {
        $this->calls[] = 'commit';

        return parent::commit();
    }

    /**
     * @return bool True when the transaction was rolled back
     */
    public function rollBack(): bool
    {
        $this->calls[] = 'rollBack';

        return parent::rollBack();
    }
}
