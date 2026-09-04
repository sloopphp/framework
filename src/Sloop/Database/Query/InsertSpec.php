<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * The parts of an INSERT statement, in the shape a Grammar reads them.
 *
 * The columns are held once and the rows as lists of values in that column
 * order, which is how MySQL writes the statement: one column list followed by
 * a tuple per row. A builder that collected rows as column-to-value maps has
 * to agree on the columns before it gets here.
 *
 * Whether there is anything to insert is not checked here. A spec describes
 * the statement it was handed, and a builder that collected no row has a
 * caller to answer to rather than a malformed clause.
 *
 * @internal Part of the seam between a query builder and a Grammar.
 */
final readonly class InsertSpec
{
    /**
     * Columns being written, in the order they are written in.
     *
     * @var list<string>
     */
    public array $columns;

    /**
     * Rows to insert, each holding one value per column in the column order.
     *
     * @var list<list<string|int|float|bool|Expression|null>>
     */
    public array $rows;

    /**
     * Columns to overwrite when a row collides with an existing one.
     *
     * Empty for a plain INSERT. Every name here is also in $columns, since the
     * value written is the one this statement was carrying for that row.
     *
     * @var list<string>
     */
    public array $upsert;

    /**
     * Describe one INSERT statement.
     *
     * @param  string                   $table    Table to insert into, optionally schema qualified
     * @param  array<int|string, mixed> $columns  Column names
     * @param  array<int|string, mixed> $rows     Rows, each a list of values in the column order
     * @param  bool                     $ignore   Whether to write INSERT IGNORE
     * @param  array<int|string, mixed> $upsert   Columns to overwrite on a collision; empty for a plain INSERT
     * @param  bool                     $rowAlias Whether the server reads the row alias form of the update
     * @throws InvalidArgumentException When a column is not a string, a row is not a list of writable values, a row does not match the columns, or an upsert column is not among them
     */
    public function __construct(
        public string $table,
        array $columns = [],
        array $rows = [],
        public bool $ignore = false,
        array $upsert = [],
        public bool $rowAlias = false,
    ) {
        $this->columns = ClauseParts::toColumnNames($columns);
        $this->rows    = ClauseParts::toValueRows($rows, \count($this->columns));
        $this->upsert  = self::toUpsertColumns($upsert, $this->columns);
    }

    /**
     * Check the columns to overwrite against the ones the statement writes.
     *
     * @param  array<int|string, mixed> $upsert  Columns to overwrite on a collision
     * @param  list<string>             $columns Columns the statement writes
     * @return list<string>             The names, reindexed
     * @throws InvalidArgumentException When a name is not a string, or names a column the statement does not write
     */
    private static function toUpsertColumns(array $upsert, array $columns): array
    {
        $names = ClauseParts::toColumnNames($upsert);

        foreach ($names as $name) {
            if (!\in_array($name, $columns, true)) {
                throw new InvalidArgumentException(
                    'A column overwritten on a collision takes the value this statement was writing for it, so it has'
                    . ' to be one of the columns being written; "' . $name . '" is not among '
                    . ($columns === [] ? 'any' : '"' . implode('", "', $columns) . '"') . '.',
                );
            }
        }

        return $names;
    }
}
