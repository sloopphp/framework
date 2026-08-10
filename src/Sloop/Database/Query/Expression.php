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
 * Quoting a column assumes the connection charset is ASCII transparent, which
 * the default utf8mb4 is. Under a charset where a multi-byte character may end
 * in a backtick byte (gbk, big5, sjis, cp932), doubling backticks byte by byte
 * no longer closes off the identifier.
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
            'FIELD(' . self::quoteIdentifier($column) . ', ' . self::placeholders(\count($bindings)) . ')',
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
        return new self(self::quoteIdentifier($column) . ' + ' . $by, []);
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
        return new self(self::quoteIdentifier($column) . ' - ' . $by, []);
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
     * Quote a possibly qualified identifier for MySQL.
     *
     * Splits on '.' and wraps each part in backticks, doubling any backtick
     * inside it. Every part has to be non-empty, which rejects the shapes that
     * would otherwise produce the empty identifier '``'.
     *
     * @param  string                   $identifier Identifier, optionally qualified ('users.score')
     * @return string                   Backtick-quoted identifier
     * @throws InvalidArgumentException When any segment is empty
     */
    private static function quoteIdentifier(string $identifier): string
    {
        $segments = explode('.', $identifier);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException(
                    'Identifier must not contain an empty segment, got ' . $identifier . '.',
                );
            }
        }

        return implode(
            '.',
            array_map(
                static fn (string $segment): string => '`' . str_replace('`', '``', $segment) . '`',
                $segments,
            ),
        );
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
