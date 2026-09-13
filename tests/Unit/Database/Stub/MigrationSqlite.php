<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

use Pdo\Sqlite;
use PDOStatement;

/**
 * SQLite connection that accepts the migration history table's MySQL DDL.
 *
 * MigrationHistory creates its table with MySQL syntax, which SQLite rejects.
 * This connection swaps that one statement for a SQLite table of the same
 * columns and passes every other statement through, so the migrator can run
 * end to end in unit tests. The DDL itself is pinned by MigrationHistoryTest
 * and exercised against both servers in the integration suite.
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
     * @param  string                  $query   Statement to prepare
     * @param  array<array-key, mixed> $options Driver options
     * @return PDOStatement|false      Prepared statement, or false on failure
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match(self::HISTORY_DDL, $query, $matches) === 1) {
            $query = 'CREATE TABLE IF NOT EXISTS ' . $matches[1] . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'name TEXT NOT NULL UNIQUE, batch INTEGER NOT NULL, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)';
        }

        return parent::prepare($query, $options);
    }
}
