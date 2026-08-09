<?php

declare(strict_types=1);

namespace Sloop\Tests\Support;

use RuntimeException;
use Sloop\Database\Connection;

/**
 * Loader for the schema shared by integration tests.
 *
 * The schema lives in tests/Integration/Database/fixtures/schema.sql so it can
 * be read as SQL rather than assembled from string concatenation in PHP.
 *
 * Statements are split on semicolons, which is sufficient for DDL but would
 * break on a semicolon inside a string literal; the fixture file documents the
 * same constraint.
 */
final class SharedSchema
{
    /**
     * Execute every statement in the fixture file against the connection.
     *
     * The statements are DDL, which commits implicitly on MySQL, so this must
     * run outside any transaction the caller wants to keep open.
     *
     * @param  Connection       $connection Connection the schema is created on
     * @return void
     * @throws RuntimeException When the fixture file is unreadable or yields no statements
     */
    public static function load(Connection $connection): void
    {
        foreach (self::statements(self::path()) as $statement) {
            $connection->statement($statement);
        }
    }

    /**
     * Absolute path to the fixture file.
     *
     * @return string Path to tests/Integration/Database/fixtures/schema.sql
     */
    public static function path(): string
    {
        return __DIR__ . '/../Integration/Database/fixtures/schema.sql';
    }

    /**
     * Collect the table names the statements create.
     *
     * Lets a caller cover every table in the fixture without repeating the
     * names, so a table added to the schema is not silently left out.
     *
     * @param  list<string>     $statements Statements as returned by statements()
     * @return list<string>     Table names in the order they are created
     * @throws RuntimeException When no statement creates a table
     */
    public static function tableNames(array $statements): array
    {
        $names = [];

        foreach ($statements as $statement) {
            if (preg_match('/^CREATE TABLE\s+(\w+)/i', $statement, $matches) === 1) {
                $names[] = $matches[1];
            }
        }

        if ($names === []) {
            throw new RuntimeException('No statement in the schema fixture creates a table.');
        }

        return $names;
    }

    /**
     * Split a schema file into individual SQL statements.
     *
     * Takes the path explicitly so the parsing and both failure modes can be
     * asserted without a database and without touching the real fixture.
     *
     * @param  string           $path Path to a schema file
     * @return list<string>     Statements in file order, comments and blank lines removed
     * @throws RuntimeException When the file is unreadable or yields no statements
     */
    public static function statements(string $path): array
    {
        $sql = is_readable($path) ? file_get_contents($path) : false;

        if ($sql === false) {
            throw new RuntimeException('Could not read the schema fixture at ' . $path . '.');
        }

        // All three comment forms are stripped before splitting. A comment left
        // in place either shifts the split point (# and /* */ can hide or
        // introduce a semicolon) or survives as a comment-only fragment, which
        // MySQL accepts and executes as a no-op without reporting anything.
        $withoutBlocks   = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
        $withoutComments = preg_replace('/^\s*(?:--|#).*$/m', '', $withoutBlocks) ?? $withoutBlocks;

        $statements = [];

        foreach (explode(';', $withoutComments) as $candidate) {
            $trimmed = trim($candidate);

            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
        }

        if ($statements === []) {
            // Returning an empty list would let every caller "succeed" without
            // creating a single table, and the failure would only surface much
            // later as a missing-table error in an unrelated test.
            throw new RuntimeException('The schema fixture at ' . $path . ' contains no statements.');
        }

        return $statements;
    }
}
