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
     * Describe one INSERT statement.
     *
     * @param  string                   $table   Table to insert into, optionally schema qualified
     * @param  array<int|string, mixed> $columns Column names
     * @param  array<int|string, mixed> $rows    Rows, each a list of values in the column order
     * @param  bool                     $ignore  Whether to write INSERT IGNORE
     * @throws InvalidArgumentException When a column is not a string, a row is not a list of writable values, or a row does not match the columns
     */
    public function __construct(
        public string $table,
        array $columns = [],
        array $rows = [],
        public bool $ignore = false,
    ) {
        $this->columns = ClauseParts::toColumnNames($columns);
        $this->rows    = ClauseParts::toValueRows($rows, \count($this->columns));
    }
}
