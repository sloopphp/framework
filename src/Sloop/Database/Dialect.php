<?php

declare(strict_types=1);

namespace Sloop\Database;

/**
 * Database server dialect detected from SELECT VERSION().
 *
 * Used to branch SQL syntax that differs between MySQL and MariaDB —
 * currently UPSERT alias syntax (Phase 5-3) and query timeout settings
 * (max_execution_time vs max_statement_time).
 */
enum Dialect: string
{
    case MySQL   = 'mysql';
    case MariaDB = 'mariadb';

    /**
     * Detect the server dialect from the VERSION() string.
     *
     * MariaDB's VERSION() output contains the "MariaDB" marker (even when the
     * compatibility prefix "5.5.5-" is present). Anything else is treated as MySQL.
     *
     * @param  string $versionString Output of `SELECT VERSION()`
     * @return self   Detected dialect
     */
    public static function detect(string $versionString): self
    {
        return str_contains($versionString, 'MariaDB') ? self::MariaDB : self::MySQL;
    }
}
