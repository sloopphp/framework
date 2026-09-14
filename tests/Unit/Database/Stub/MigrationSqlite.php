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
 * migrator can run end to end in unit tests. The DDL itself is pinned by MigrationHistoryTest
 * and exercised against both servers in the integration suite.
 *
 * rollBack() can also be made to fail, for the path where a rollback fails
 * while a migration's exception is on its way out.
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
