<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

use Pdo\Sqlite;
use PDOException;
use PDOStatement;

/**
 * SQLite connection that accepts the migration history table's MySQL DDL.
 *
 * MigrationHistory creates its table with MySQL syntax, which SQLite rejects.
 * This connection swaps that statement for a SQLite table of the same
 * columns, and the table's existence check against information_schema for
 * one against sqlite_master, and passes every other statement through, so the
 * migrator can run end to end in unit tests. The DDL itself is pinned by
 * MigrationHistoryTest and exercised against both servers in the integration
 * suite.
 *
 * The server's autocommit setting is stood in for as well: reading it
 * returns $autocommit, and setting it records the value in
 * $autocommitSettings.
 *
 * rollBack() can also be made to fail, for the path where a rollback fails
 * while a migration's exception is on its way out, and turning autocommit off
 * can be made to fail in the same way.
 */
final class MigrationSqlite extends Sqlite
{
    /**
     * The history table DDL, capturing the quoted table name.
     *
     * @var string
     */
    private const string HISTORY_DDL = '/\ACREATE TABLE IF NOT EXISTS (`[^`]+`) \(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, /';

    /**
     * The history table's existence check against information_schema.
     *
     * @var string
     */
    private const string EXISTS_QUERY = 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';

    /**
     * The autocommit setting reads return: 1 for on, 0 for off, read back as a string when given as one.
     *
     * @var int|string
     */
    public int|string $autocommit = 1;

    /**
     * Every autocommit value set, in order.
     *
     * @var list<int>
     */
    public array $autocommitSettings = [];

    /**
     * Whether turning autocommit off fails.
     *
     * @var bool
     */
    public bool $failTurningAutocommitOff = false;

    /**
     * Whether rollBack() fails instead of rolling back.
     *
     * @var bool
     */
    public bool $failRollBack = false;

    /**
     * @param  string                  $query   Statement to prepare
     * @param  array<array-key, mixed> $options Driver options
     * @return PDOStatement|false      Prepared statement, or false on failure
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($query === 'SELECT @@autocommit AS autocommit') {
            $query = \is_string($this->autocommit)
                ? 'SELECT \'' . $this->autocommit . '\' AS autocommit'
                : 'SELECT ' . $this->autocommit . ' AS autocommit';
        }

        if (preg_match('/\ASET autocommit = ([01])\z/', $query, $setting) === 1) {
            if ($setting[1] === '0' && $this->failTurningAutocommitOff) {
                throw new PDOException('Setting autocommit failed.');
            }

            $this->autocommit           = (int) $setting[1];
            $this->autocommitSettings[] = $this->autocommit;
            $query = 'SELECT 1';
        }

        if ($query === self::EXISTS_QUERY) {
            $query = 'SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = ?';
        }

        if (preg_match(self::HISTORY_DDL, $query, $matches) === 1) {
            $query = 'CREATE TABLE IF NOT EXISTS ' . $matches[1] . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'name TEXT NOT NULL UNIQUE, batch INTEGER NOT NULL, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)';
        }

        return parent::prepare($query, $options);
    }

    /**
     * @return bool True when the transaction was rolled back
     */
    public function rollBack(): bool
    {
        if ($this->failRollBack) {
            throw new PDOException('Rollback failed.');
        }

        return parent::rollBack();
    }
}
