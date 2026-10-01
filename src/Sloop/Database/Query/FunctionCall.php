<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;
use LogicException;

/**
 * A function call, to be selected as an aggregate or given a window.
 *
 * The named factories on Expression return one of these. `over()` turns it
 * into the WindowExpression a Grammar compiles. Splitting the call from its
 * window is what lets the two be written in the order they are read:
 * `Expression::sum('price')->over()->partitionBy('status')` names the function
 * first and describes the window after, where the factory form has to hold
 * both at once.
 *
 * Without `over()` it may stand only in the select list, where it is an
 * aggregate and is checked against the grammar's list of those. The functions
 * that run only over a window are not on it, so one of them written without a
 * window is refused where it was written rather than compiling to SQL that
 * neither server accepts.
 *
 * What may stand as an argument, and which calls may be written DISTINCT or
 * with a sort order and separator, is settled by the signatures of the
 * factories that build these. A call carrying any of those three is an
 * aggregate only: neither server gives one a window.
 *
 * @see Expression::fn() for the factory that names any function a Grammar writes
 * @see Expression::over() for building the same call and its window in one go
 */
final readonly class FunctionCall
{
    /**
     * Name of the function, trimmed; which names may stand is a Grammar's to say.
     *
     * @var string
     */
    public string $function;

    /**
     * Describe one function call.
     *
     * The arguments tell columns from values apart by type, the same way
     * WindowExpression reads them: a string names a column and is quoted, an
     * Expression is written as it stands, and anything else is bound.
     *
     * The keys are whatever the caller's array had -- `fn()` spread with named
     * keys leaves them string -- and are dropped when over() hands the call to
     * WindowExpression, which reads the arguments in written order.
     *
     * DISTINCT reads the values of a column, so a call written DISTINCT that
     * has `*` among its arguments is refused here; both servers reject
     * `COUNT(DISTINCT *)`.
     *
     * @param  string                                                   $function  Name of the function, in any case
     * @param  array<int|string, string|Expression|int|float|bool|null> $arguments Arguments of the call, in written order
     * @param  bool                                                     $distinct  Whether the call reads each distinct value once
     * @param  list<Order>                                              $orders    Sort terms the call reads its values in, written inside the parentheses
     * @param  string|null                                              $separator Text joining the values, or null to write none and leave the server's comma
     * @throws InvalidArgumentException                                 When the function name is empty, or a DISTINCT call is given `*`
     */
    public function __construct(
        string $function,
        public array $arguments = [],
        public bool $distinct = false,
        public array $orders = [],
        public ?string $separator = null,
    ) {
        $this->function = trim($function);

        if ($this->function === '') {
            throw new InvalidArgumentException('A function call needs a function name.');
        }

        if ($distinct && \in_array('*', $arguments, true)) {
            throw new InvalidArgumentException(
                'DISTINCT reads the values of a column, so it takes no *. Name the column to count the distinct values of.',
            );
        }
    }

    /**
     * Give the call a window, so it runs over a frame of rows rather than the whole result.
     *
     * The window starts out empty, which both servers accept and read as every
     * row the statement returns. `partitionBy()` and `orderBy()` on what this
     * returns narrow it from there.
     *
     * @return WindowExpression The call as a window expression, with no partitions or sort terms yet
     * @throws LogicException   When the call is written DISTINCT, or with a sort order or separator
     */
    public function over(): WindowExpression
    {
        // Both servers refuse a window on these (1235), and a window has no
        // place to keep them, so they would otherwise be dropped unseen.
        if ($this->distinct || $this->orders !== [] || $this->separator !== null) {
            throw new LogicException(
                'A call written with DISTINCT, an ORDER BY, or a SEPARATOR takes no window; neither server accepts one.',
            );
        }

        return Expression::over($this->function, $this->arguments);
    }
}
