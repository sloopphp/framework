<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * A raw SQL fragment with its own bindings.
 *
 * Anywhere a query builder accepts a value it also accepts an Expression, and
 * the SQL is embedded verbatim instead of being quoted as an identifier or sent
 * as a bound value. The table prefix is never applied to an Expression, so any
 * table name inside one has to be written out in full.
 *
 * The static factories cover the MySQL functions that are awkward to write by
 * hand. They quote the column they are given and bind the values, so only the
 * SQL text passed to `of()` is taken as-is.
 *
 * Columns are quoted by IdentifierQuoter, the same way a query builder quotes
 * the ones it is given. That quoting does not carry the table prefix, which is
 * why `over()` is the exception here: it returns a WindowExpression, whose
 * columns a Grammar resolves when the statement is compiled and so reach the
 * prefix the way any other column reference does.
 */
final readonly class Expression
{
    /**
     * @param string            $sql      Raw SQL fragment, embedded verbatim
     * @param list<scalar|null> $bindings Values for the placeholders in $sql, in order
     */
    private function __construct(
        private string $sql,
        private array $bindings,
    ) {
    }

    /**
     * Create an expression from raw SQL.
     *
     * The SQL is never parsed or rewritten, so anything interpolated into it is
     * the caller's responsibility. Use placeholders and $bindings for values
     * that come from outside the application.
     *
     * @param  string                   $sql      Raw SQL fragment, embedded verbatim
     * @param  array<int|string, mixed> $bindings Values for the placeholders in $sql, in order
     * @return self                     Expression carrying the SQL and its bindings
     * @throws InvalidArgumentException When $bindings is not a list, or holds a value PDO cannot bind
     */
    public static function of(string $sql, array $bindings = []): self
    {
        if (!array_is_list($bindings)) {
            throw new InvalidArgumentException(
                'Bindings must be a list, so that their order matches the placeholders in the SQL.',
            );
        }

        return new self($sql, self::toBindings($bindings, 'Bindings'));
    }

    /**
     * Build `FIELD(column, ...)`, which returns the position of the column value in $values.
     *
     * Useful for ordering by a hand-written sequence of states, or for turning a
     * column value into an index. Values not listed give position 0.
     *
     * @param  string                   $column Column name, optionally qualified ('users.status')
     * @param  array<int|string, mixed> $values Candidate values, in the order that defines the positions
     * @return self                     Expression for the FIELD() call
     * @throws InvalidArgumentException When $column has an empty segment, $values is empty, or a value cannot be bound
     */
    public static function field(string $column, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('FIELD() requires at least one value.');
        }

        $bindings = self::toBindings($values, 'Values');

        return new self(
            'FIELD(' . IdentifierQuoter::quote($column) . ', ' . self::placeholders(\count($bindings)) . ')',
            $bindings,
        );
    }

    /**
     * Build `ELT(n, ...)`, which returns the nth value of $values.
     *
     * $position may be an expression, which is how this pairs with `field()`:
     * one maps a column value to a position, the other picks the value at that
     * position. Positions are 1-based; out-of-range gives NULL.
     *
     * @param  int|self                 $position 1-based position, or an expression producing one
     * @param  array<int|string, mixed> $values   Values to pick from, in position order
     * @return self                     Expression for the ELT() call
     * @throws InvalidArgumentException When $values is empty, or a value cannot be bound
     */
    public static function elt(int|self $position, array $values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('ELT() requires at least one value.');
        }

        $bindings         = self::toBindings($values, 'Values');
        $positionSql      = $position instanceof self ? $position->sql : (string) $position;
        $positionBindings = $position instanceof self ? $position->bindings : [];

        return new self(
            'ELT(' . $positionSql . ', ' . self::placeholders(\count($bindings)) . ')',
            array_merge($positionBindings, $bindings),
        );
    }

    /**
     * Build a window function call, such as `ROW_NUMBER() OVER (PARTITION BY ...)`.
     *
     * Everything here is named rather than written as SQL, so a Grammar quotes
     * the columns and reaches them with the table prefix. That is the
     * difference from writing the same call through `of()`, where the text is
     * embedded as it stands and both are the caller's problem.
     *
     * Arguments tell columns from values apart by type: a string names a
     * column, an Expression is written as it stands, and anything else is
     * bound. `$orders` takes a column name for each term, with a direction
     * where a string key gives one — `['score' => 'DESC', 'id']` sorts by score
     * descending and then by id ascending. An Expression stands as a term of
     * its own and carries no direction, since its SQL already says how it
     * sorts. A column whose name is written in digits cannot take a direction
     * that way, because PHP reads such a key as an integer; write it as an
     * Expression instead.
     *
     * Unlike the other factories here this returns a WindowExpression, since
     * what it describes is resolved when the statement is compiled rather than
     * held as finished SQL.
     *
     * @param  string                   $function   Name of the window function, in any case
     * @param  array<int|string, mixed> $arguments  Arguments of the call, in written order
     * @param  array<int|string, mixed> $partitions Columns to divide the rows by before the function runs
     * @param  array<int|string, mixed> $orders     Sort terms within a partition, as column or column => direction
     * @param  string|null              $alias      Name to read the result under, or null for none
     * @return WindowExpression         The call, with its columns left for a Grammar to quote
     * @throws InvalidArgumentException When the function name is empty, an element cannot stand where it is, a direction names none, or the alias is qualified
     */
    public static function over(
        string $function,
        array $arguments = [],
        array $partitions = [],
        array $orders = [],
        ?string $alias = null,
    ): WindowExpression {
        return new WindowExpression($function, $arguments, $partitions, self::toOrders($orders), $alias);
    }

    /**
     * Build `` `column` + n ``, so the column is read and written in one statement.
     *
     * Unlike a read-then-write in PHP, this cannot lose a concurrent update.
     * A negative $by is kept as written, which MySQL reads as a subtraction.
     *
     * @param  string                   $column Column name, optionally qualified ('users.score')
     * @param  int                      $by     Amount to add
     * @return self                     Expression for the addition
     * @throws InvalidArgumentException When $column has an empty segment
     */
    public static function increment(string $column, int $by = 1): self
    {
        return new self(IdentifierQuoter::quote($column) . ' + ' . $by, []);
    }

    /**
     * Build `` `column` - n ``, so the column is read and written in one statement.
     *
     * The counterpart of `increment()`; see there for why this is not a
     * read-then-write. A negative $by is kept as written, which MySQL reads as
     * an addition.
     *
     * @param  string                   $column Column name, optionally qualified ('users.stock')
     * @param  int                      $by     Amount to subtract
     * @return self                     Expression for the subtraction
     * @throws InvalidArgumentException When $column has an empty segment
     */
    public static function decrement(string $column, int $by = 1): self
    {
        return new self(IdentifierQuoter::quote($column) . ' - ' . $by, []);
    }

    /**
     * The raw SQL fragment.
     *
     * @return string SQL to embed verbatim
     */
    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * The values for the placeholders in the SQL fragment.
     *
     * @return list<scalar|null> Bindings in placeholder order
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * Read the sort terms of a window as the Order instances a Grammar reads.
     *
     * A string key names the column and the value gives its direction; an
     * integer key means the value is the column and it sorts ascending, or an
     * Expression whose SQL already carries one. That lets the common case stay
     * a plain list of names while a term that needs DESC says so next to the
     * column it applies to.
     *
     * @param  array<int|string, mixed> $orders Sort terms as the caller gave them
     * @return list<Order>              Sort terms in written order
     * @throws InvalidArgumentException When a column is not a name or an expression, or a direction names none
     */
    private static function toOrders(array $orders): array
    {
        $list = [];

        foreach ($orders as $key => $value) {
            if (\is_string($key)) {
                if (!\is_string($value)) {
                    throw new InvalidArgumentException(
                        'A sort direction is written as a string, got ' . get_debug_type($value)
                        . ' for ' . $key . '.',
                    );
                }

                $list[] = new Order($key, Direction::fromKeyword($value));

                continue;
            }

            if (!\is_string($value) && !$value instanceof self) {
                // Counted from the start of the list rather than reported under
                // the key it was given, because the keys here are what tells a
                // column from a column-and-direction: an integer one carries no
                // meaning of its own and would send the reader looking for a
                // position that is not the one they wrote.
                throw new InvalidArgumentException(
                    'A sort term names a column or is an Expression, got '
                    . get_debug_type($value) . ' at index ' . \count($list) . '.',
                );
            }

            // A column whose name is written in digits arrives under an integer
            // key, because that is what PHP turns a numeric string into. The
            // direction meant for it is then read as the column itself, so a
            // term that looks like one says so rather than sorting by a column
            // called ASC. Such a column takes its direction through an
            // Expression, or through a key PHP keeps as a string.
            if (\is_string($value) && Direction::tryFrom(strtoupper($value)) !== null) {
                throw new InvalidArgumentException(
                    'A sort direction stands where a column is named, got "' . $value
                    . '". A column whose name is a number takes its direction as an Expression,'
                    . ' because PHP reads a numeric key as an integer.',
                );
            }

            // An Expression already says how it sorts, so no direction is
            // appended to it -- the same rule orderByRaw() follows.
            $list[] = $value instanceof self ? new Order($value, null) : new Order($value);
        }

        return $list;
    }

    /**
     * Reindex values as a list and reject anything PDO cannot bind.
     *
     * The element type is checked here rather than left to PDO so that the
     * failure names the offending value at the call site, instead of surfacing
     * as a bind error once the expression reaches a statement.
     *
     * @param  array<int|string, mixed> $values Values to bind, in placeholder order
     * @param  string                   $label  Noun for the message, naming what the caller passed
     * @return list<scalar|null>        Values as a list
     * @throws InvalidArgumentException When a value is neither scalar nor null
     */
    private static function toBindings(array $values, string $label): array
    {
        $bindings = [];

        foreach (array_values($values) as $index => $value) {
            if ($value !== null && !\is_scalar($value)) {
                throw new InvalidArgumentException(
                    $label . ' must be scalar or null, got ' . get_debug_type($value) . ' at index ' . $index . '.',
                );
            }

            $bindings[] = $value;
        }

        return $bindings;
    }

    /**
     * Build a comma separated list of positional placeholders.
     *
     * @param  int    $count Number of placeholders, always at least one
     * @return string Placeholders joined with ', '
     */
    private static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
