<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;
use Sloop\Database\ConnectionRoute;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\InvalidConfigException;

/**
 * An UPDATE statement being built.
 *
 * Obtained from Connection::update() to change rows through one connection, or
 * from ConnectionManager::update() to change them through a pool's primary.
 * The table is named when the builder is made, the columns to write are given
 * to set(), and the rows to write them to are picked by the same conditions a
 * SELECT is narrowed with.
 *
 * ```php
 * $changed = $connection->update('users')
 *     ->set(['status' => 'active', 'score' => Expression::of('score + 1')])
 *     ->where('status', 'pending')
 *     ->execute();
 * ```
 *
 * A statement with no conditions writes to every row in the table. That is
 * what UPDATE means and the builder writes it as asked; the guard that refuses
 * it unless it was asked for deliberately arrives with the `strict_mode`
 * config key.
 */
class Update extends BuilderWhere
{
    /**
     * Columns to write, kept under the column name so a later set() replaces an earlier one.
     *
     * @var array<string, Assignment>
     */
    protected array $assignments = [];

    /**
     * Start an UPDATE against the given table.
     *
     * @param ConnectionRoute $route   Route asked for a connection when the statement runs
     * @param Grammar         $grammar Grammar that turns the collected parts into SQL
     * @param string          $table   Table to update, optionally schema qualified
     */
    public function __construct(ConnectionRoute $route, Grammar $grammar, private readonly string $table)
    {
        parent::__construct($route, $grammar);
    }

    /**
     * Add the columns to write and the values to write to them.
     *
     * Keys name the columns and may be table qualified. Naming a key that was
     * named before replaces the value it was going to get and leaves it where
     * it was in the clause, so that the statement writes each key once
     * whichever call settled it.
     *
     * What is compared is the key as it was written, not the column it
     * resolves to: naming one column once plainly and once table qualified
     * leaves two assignments, since telling those apart needs the statement to
     * know which names stand for the same column.
     *
     * A value is bound rather than written into the SQL, which is why one that
     * has to be read as SQL — a column standing for what it already holds, or
     * a function call over it — is passed as an Expression instead.
     *
     * @param  array<int|string, mixed> $values Column name to the value to write
     * @return static                   This builder
     * @throws InvalidArgumentException When a key does not name a column, or a value cannot be written
     */
    public function set(array $values): static
    {
        $index = 0;

        foreach ($values as $column => $value) {
            if (!\is_string($column)) {
                throw new InvalidArgumentException(
                    'An assignment names the column it writes, so its key must be a string, got '
                    . get_debug_type($column) . ' at index ' . $index . '.',
                );
            }

            $this->assignments[$column] = new Assignment($column, self::toWritable($value, $column));
            $index++;
        }

        return $this;
    }

    /**
     * Write this statement as SQL together with the values its placeholders need.
     *
     * @return CompiledSql
     * @throws LogicException           When there is nothing to assign, a group of conditions was left open, or an offset was set
     * @throws InvalidArgumentException When an identifier is malformed
     */
    public function compile(): CompiledSql
    {
        $this->requireGroupsClosed();

        if ($this->assignments === []) {
            throw new LogicException(
                'An UPDATE writes at least one column, and this one names none; call set() before running it.',
            );
        }

        if ($this->offset !== null) {
            throw new LogicException(
                'An UPDATE takes no offset: MySQL orders the rows and changes the first LIMIT of them,'
                    . ' with nothing to skip past. Narrow the statement with where() instead.',
            );
        }

        return $this->grammar->compileUpdate(new UpdateSpec(
            table:       $this->table,
            assignments: $this->assignments,
            conditions:  $this->conditions,
            orders:      $this->orders,
            limit:       $this->limit,
        ));
    }

    /**
     * Run this statement and return how many rows it changed.
     *
     * The count is the server's, which reports the rows it wrote rather than
     * the rows it matched: a row already holding the values being written is
     * matched and not counted.
     *
     * The connection is asked for here rather than when the builder was made,
     * so where this runs is whatever the route answers now.
     *
     * @return int                         Rows changed
     * @throws LogicException              When there is nothing to assign, a group of conditions was left open, or an offset was set
     * @throws InvalidArgumentException    When an identifier is malformed
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     */
    public function execute(): int
    {
        $compiled = $this->compile();

        return $this->route->connection()->statement($compiled->sql, $compiled->bindings);
    }

    /**
     * Narrow a value to the ones an assignment can carry.
     *
     * @param  mixed                                 $value  Value handed to set()
     * @param  string                                $column Column it was given for, to name in the message
     * @return string|int|float|bool|Expression|null The same value, once it is one that can be written
     * @throws InvalidArgumentException              When the value is of another type
     */
    private static function toWritable(mixed $value, string $column): string|int|float|bool|Expression|null
    {
        if ($value !== null && !\is_scalar($value) && !$value instanceof Expression) {
            throw new InvalidArgumentException(
                'The value of an assignment must be a scalar, null or an Expression, got '
                . get_debug_type($value) . ' for column "' . $column . '".',
            );
        }

        return $value;
    }
}
