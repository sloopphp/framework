<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * A window function call, held as its parts rather than as SQL.
 *
 * Unlike an Expression, which carries SQL that is written out as it stands,
 * this names its columns and lets a Grammar quote them. That is what puts a
 * window function under the same guarantees as any other column reference: the
 * identifiers are quoted, and the table prefix reaches the tables named inside
 * the OVER clause. Writing the same call with Expression::of() leaves both to
 * whoever assembles the string.
 *
 * The function name is checked by a Grammar rather than here, because which
 * calls a server accepts is a property of the dialect. The shape of the
 * arguments is checked in the constructor, so a value that cannot stand where
 * it was put says so at the line that put it.
 *
 * It carries no name to return the result under. That is given the way any
 * other column's is, as `[$window, $name]` in the select list, which keeps the
 * name out of an ORDER BY term where neither server accepts one.
 *
 * @see Expression::over() for the factory that builds one of these
 */
final readonly class WindowExpression
{
    /**
     * Name of the function, trimmed; which names may stand is a Grammar's to say.
     *
     * @var string
     */
    public string $function;

    /**
     * Arguments of the function call, in the order they are written.
     *
     * @var list<string|Expression|int|float|bool|null>
     */
    public array $arguments;

    /**
     * Columns the rows are divided by before the function runs over each group.
     *
     * @var list<string|Expression>
     */
    public array $partitions;

    /**
     * Terms deciding the order the function sees the rows of a partition in.
     *
     * @var list<Order>
     */
    public array $orders;

    /**
     * Describe one window function call.
     *
     * The arguments tell columns from values apart by type: a string names a
     * column and is quoted, an Expression is written as it stands, and anything
     * else is bound. A column that has to be written as a value — a numeric
     * literal, say — goes in as an Expression.
     *
     * The sort terms are Order instances, the same shape a Grammar reads an
     * ORDER BY clause from. Expression::over() is what turns the column and
     * direction a caller writes into them, and it is the way to build one of
     * these that checks what the terms hold. Handed an Order directly, this
     * takes it as given: a term carrying a window call of its own, or an
     * Expression carrying a direction, compiles to SQL that both servers
     * refuse. Neither is reachable through the factory.
     *
     * @param  string                   $function   Name of the window function, in any case
     * @param  array<int|string, mixed> $arguments  Arguments of the call, in written order
     * @param  array<int|string, mixed> $partitions Columns to divide the rows by
     * @param  array<int|string, mixed> $orders     Order instances deciding the order within a partition
     * @throws InvalidArgumentException When the function name is empty, a list is not one, or an element cannot stand where it is
     */
    public function __construct(
        string $function,
        array $arguments = [],
        array $partitions = [],
        array $orders = [],
    ) {
        $this->function = trim($function);

        if ($this->function === '') {
            throw new InvalidArgumentException('A window function call needs a function name.');
        }

        $this->arguments  = self::toArguments($arguments);
        $this->partitions = self::toPartitions($partitions);
        $this->orders     = self::toOrders($orders);
    }

    /**
     * Divide the rows by these columns as well, before the function runs over each group.
     *
     * Adds to the columns already there rather than replacing them, so a window
     * may be narrowed a column at a time. The window itself is never changed: a
     * new one is returned, and the one this was called on keeps what it had.
     *
     * A window call cannot stand here. Nesting one inside another compiles to
     * SQL both servers refuse (MySQL 3595, MariaDB 4015), so the signature says
     * so at the line that wrote it.
     *
     * @param  string|Expression ...$columns Columns to divide the rows by, or expressions producing them
     * @return self              A window dividing the rows by the columns it had and these
     */
    public function partitionBy(string|Expression ...$columns): self
    {
        return new self(
            $this->function,
            $this->arguments,
            [...$this->partitions, ...$columns],
            $this->orders,
        );
    }

    /**
     * Sort the rows of each partition by this column as well.
     *
     * Adds to the sort terms already there rather than replacing them, so a
     * window may be ordered by one column and then another. As with
     * partitionBy(), a new window is returned and this one is left alone.
     *
     * The rules are the ones a statement's ORDER BY follows, so the same call
     * reads the same way inside a window as outside it: an Expression stands as
     * a term of its own and takes no direction, since its SQL already says how
     * it sorts, and a window call cannot stand here at all.
     *
     * @param  string|Expression        $column    Column to sort by, or an expression producing the sort key
     * @param  string                   $direction Sort direction, 'ASC' or 'DESC'
     * @return self                     A window sorting by the terms it had and this one
     * @throws InvalidArgumentException When the direction names neither ASC nor DESC, or is given alongside an Expression
     *
     * @see    BuilderWhere::orderBy() for the same rules applied to a statement
     */
    public function orderBy(string|Expression $column, string $direction = 'ASC'): self
    {
        // Read even where it is not written, so that a direction naming neither
        // ASC nor DESC is refused the same way whatever the column is.
        $read = Direction::fromKeyword($direction);

        if ($column instanceof Expression) {
            if (\func_num_args() > 1) {
                throw new InvalidArgumentException(
                    'A sort term written as SQL says how it sorts, so it takes no direction, got "'
                    . $direction . '". Write the direction into the Expression instead.',
                );
            }

            $read = null;
        }

        return new self(
            $this->function,
            $this->arguments,
            $this->partitions,
            [...$this->orders, new Order($column, $read)],
        );
    }

    /**
     * Reindex the arguments as a list and reject what cannot be written as one.
     *
     * @param  array<int|string, mixed>                    $arguments Arguments as the caller gave them
     * @return list<string|Expression|int|float|bool|null> Arguments as a list
     * @throws InvalidArgumentException                    When an argument is neither a column name, an expression, nor a value
     */
    private static function toArguments(array $arguments): array
    {
        $list = [];

        foreach (array_values($arguments) as $index => $argument) {
            if (
                $argument !== null
                && !\is_string($argument)
                && !\is_int($argument)
                && !\is_float($argument)
                && !\is_bool($argument)
                && !$argument instanceof Expression
            ) {
                throw new InvalidArgumentException(
                    'An argument names a column, is an Expression, or is a value to bind, got '
                    . get_debug_type($argument) . ' at index ' . $index . '.',
                );
            }

            $list[] = $argument;
        }

        return $list;
    }

    /**
     * Reindex the partitions as a list and reject what cannot name one.
     *
     * @param  array<int|string, mixed> $partitions Partitions as the caller gave them
     * @return list<string|Expression>  Partitions as a list
     * @throws InvalidArgumentException When a partition is neither a column name nor an expression
     */
    private static function toPartitions(array $partitions): array
    {
        $list = [];

        foreach (array_values($partitions) as $index => $partition) {
            if (!\is_string($partition) && !$partition instanceof Expression) {
                throw new InvalidArgumentException(
                    'A partition names a column or is an Expression, got '
                    . get_debug_type($partition) . ' at index ' . $index . '.',
                );
            }

            $list[] = $partition;
        }

        return $list;
    }

    /**
     * Reindex the sort terms as a list and reject what cannot be one.
     *
     * @param  array<int|string, mixed> $orders Sort terms as the caller gave them
     * @return list<Order>              Sort terms as a list
     * @throws InvalidArgumentException When a term is not an Order
     */
    private static function toOrders(array $orders): array
    {
        $list = [];

        foreach (array_values($orders) as $index => $order) {
            if (!$order instanceof Order) {
                throw new InvalidArgumentException(
                    'A sort term of a window is an Order, got ' . get_debug_type($order)
                    . ' at index ' . $index . '.',
                );
            }

            $list[] = $order;
        }

        return $list;
    }
}
