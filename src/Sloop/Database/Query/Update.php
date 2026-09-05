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
 * what UPDATE means and the builder writes it as asked, unless the pool sets
 * `strict_mode`, which refuses an unconditioned statement at the point it would
 * run. allowWithoutWhere() says that the whole table is what was meant.
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
     * Join a table, keeping only the rows that the ON clause pairs.
     *
     * A joined UPDATE writes to the rows the pairing leaves, which is how a
     * statement changes rows by what another table holds about them without
     * reading them first.
     *
     * The join becomes the one that on() and the methods beside it add
     * conditions to, until the next join() starts another. A join carries at
     * least one condition: one without any pairs every row with every other,
     * so the statement is refused when it is compiled rather than run.
     *
     * @param  string         $table Table to join, optionally schema qualified
     * @return static         This builder
     * @throws LogicException When the join before this one left a group of ON conditions open
     */
    public function join(string $table): static
    {
        return $this->addJoin(JoinType::Inner, $table);
    }

    /**
     * Join a table, keeping every row of the updated table whether it pairs or not.
     *
     * The rows that found no match are written to as well, since they are
     * still rows of the table being updated. Where only the paired ones are
     * meant, join() says so; narrowing a left join in where() drops the
     * unmatched rows again by testing columns that read back as null.
     *
     * @param  string         $table Table to join, optionally schema qualified
     * @return static         This builder
     * @throws LogicException When the join before this one left a group of ON conditions open
     */
    public function leftJoin(string $table): static
    {
        return $this->addJoin(JoinType::Left, $table);
    }

    /**
     * Join a table, keeping every row of it whether it pairs or not.
     *
     * @param  string         $table Table to join, optionally schema qualified
     * @return static         This builder
     * @throws LogicException When the join before this one left a group of ON conditions open
     */
    public function rightJoin(string $table): static
    {
        return $this->addJoin(JoinType::Right, $table);
    }

    /**
     * Pair rows on a comparison between two columns.
     *
     * Called with two arguments the second one is the column to compare
     * against and the comparison is `=`; called with three the second one is
     * the operator. Both sides name columns, and a value takes part in the
     * pairing only as an Expression carrying its own binding, as in
     * `Expression::of('?', [$status])`; Select::on() gives the reasoning.
     *
     * @param  string|Expression        $column    Column on the left, or an expression standing in for one
     * @param  string|Expression        $operator  Operator when a column follows, otherwise the column to compare against
     * @param  string|Expression|null   $reference Column on the right, when an operator was given
     * @return static                   This builder
     * @throws LogicException           When no join has been started
     * @throws InvalidArgumentException When the operator is not a supported comparison, or reads a keyword rather than a column
     */
    public function on(
        string|Expression $column,
        string|Expression $operator,
        string|Expression|null $reference = null,
    ): static {
        return $this->addOn(Conjunction::And, $column, $operator, $reference, \func_num_args());
    }

    /**
     * Pair rows on a comparison, joined to the one before it with AND.
     *
     * Same as on(); spelled out for a chain that reads better with the
     * conjunction named at every step.
     *
     * @param  string|Expression        $column    Column on the left, or an expression standing in for one
     * @param  string|Expression        $operator  Operator when a column follows, otherwise the column to compare against
     * @param  string|Expression|null   $reference Column on the right, when an operator was given
     * @return static                   This builder
     * @throws LogicException           When no join has been started
     * @throws InvalidArgumentException When the operator is not a supported comparison, or reads a keyword rather than a column
     */
    public function andOn(
        string|Expression $column,
        string|Expression $operator,
        string|Expression|null $reference = null,
    ): static {
        return $this->addOn(Conjunction::And, $column, $operator, $reference, \func_num_args());
    }

    /**
     * Pair rows on a comparison, joined to the one before it with OR.
     *
     * MySQL binds AND tighter than OR, so a comparison added after this one
     * joins to it rather than to the clause as a whole. Where the alternative
     * has to stay one whatever follows, open a group with orOnOpen().
     *
     * @param  string|Expression        $column    Column on the left, or an expression standing in for one
     * @param  string|Expression        $operator  Operator when a column follows, otherwise the column to compare against
     * @param  string|Expression|null   $reference Column on the right, when an operator was given
     * @return static                   This builder
     * @throws LogicException           When no join has been started
     * @throws InvalidArgumentException When the operator is not a supported comparison, or reads a keyword rather than a column
     */
    public function orOn(
        string|Expression $column,
        string|Expression $operator,
        string|Expression|null $reference = null,
    ): static {
        return $this->addOn(Conjunction::Or, $column, $operator, $reference, \func_num_args());
    }

    /**
     * Open a group of ON conditions, joined to what precedes it with AND.
     *
     * Everything added until the matching onClose() goes inside the
     * parentheses. The group belongs to the join being written now: the next
     * join() refuses to start while one is open.
     *
     * @return static         This builder
     * @throws LogicException When no join has been started
     */
    public function onOpen(): static
    {
        return $this->openOnGroup(Conjunction::And);
    }

    /**
     * Open a group of ON conditions, joined to what precedes it with AND.
     *
     * Same as onOpen(); spelled out for a chain that names the conjunction at
     * every step.
     *
     * @return static         This builder
     * @throws LogicException When no join has been started
     */
    public function andOnOpen(): static
    {
        return $this->openOnGroup(Conjunction::And);
    }

    /**
     * Open a group of ON conditions, joined to what precedes it with OR.
     *
     * @return static         This builder
     * @throws LogicException When no join has been started
     */
    public function orOnOpen(): static
    {
        return $this->openOnGroup(Conjunction::Or);
    }

    /**
     * Close the group of ON conditions opened last.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function onClose(): static
    {
        return $this->closeOnGroup();
    }

    /**
     * Close the group of ON conditions opened last.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function andOnClose(): static
    {
        return $this->closeOnGroup();
    }

    /**
     * Close the group of ON conditions opened last.
     *
     * The conjunction of a group is decided where it opens, so this reads the
     * same as onClose() and exists to end a chain that opened with orOnOpen()
     * in the matching spelling.
     *
     * @return static         This builder
     * @throws LogicException When no group is open
     */
    public function orOnClose(): static
    {
        return $this->closeOnGroup();
    }

    /**
     * Say that this statement is meant to address every row.
     *
     * Only has an effect where the connection runs in strict mode, which
     * otherwise refuses an UPDATE carrying no WHERE clause. Saying it on a
     * statement that is narrowed anyway changes nothing.
     *
     * The opt-out is per statement rather than per connection so that a batch
     * job can name itself as one without the setting being lifted for
     * everything else running over the same pool.
     *
     * @return static This builder
     */
    public function allowWithoutWhere(): static
    {
        $this->allowWithoutWhere = true;

        return $this;
    }

    /**
     * Write this statement as SQL together with the values its placeholders need.
     *
     * @return CompiledSql
     * @throws LogicException           When there is nothing to assign, a group of conditions or of ON conditions was left open, a join carries no ON condition, a join is paired with ORDER BY or LIMIT, or an offset was set
     * @throws InvalidArgumentException When an identifier is malformed
     */
    public function compile(): CompiledSql
    {
        $this->requireGroupsClosed();
        $this->requireJoinsUsable();

        if ($this->joins !== [] && ($this->orders !== [] || $this->limit !== null)) {
            throw new LogicException(
                'MySQL 8.0 refuses ORDER BY and LIMIT on an UPDATE that joins another table (error 1221),'
                    . ' while MariaDB accepts them. This one is refused here so that it means the same on'
                    . ' either server; narrow it with where() instead.',
            );
        }

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
            joins:       $this->joins,
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
     * @throws LogicException              When there is nothing to assign, a group of conditions or of ON conditions was left open, a join carries no ON condition, a join is paired with ORDER BY or LIMIT, an offset was set, or the connection is in strict mode and nothing narrows the statement
     * @throws InvalidArgumentException    When an identifier is malformed
     * @throws InvalidConfigException      When the pool name is not defined or its config is malformed
     * @throws DatabaseConnectionException When the connection cannot be obtained
     * @throws DatabaseException           When the statement fails, or a persistent connection carries a residual transaction that cannot be rolled back
     */
    public function execute(): int
    {
        $compiled   = $this->compile();
        $connection = $this->route->connection();

        $this->requireWhereUnderStrictMode($connection, 'UPDATE');

        return $connection->statement($compiled->sql, $compiled->bindings);
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
