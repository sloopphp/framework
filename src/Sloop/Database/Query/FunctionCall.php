<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

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
 * What may stand as an argument is settled by the signatures of the factories
 * that build these.
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
     * @param  string                                                   $function  Name of the function, in any case
     * @param  array<int|string, string|Expression|int|float|bool|null> $arguments Arguments of the call, in written order
     * @throws InvalidArgumentException                                 When the function name is empty
     */
    public function __construct(
        string $function,
        public array $arguments = [],
    ) {
        $this->function = trim($function);

        if ($this->function === '') {
            throw new InvalidArgumentException('A function call needs a function name.');
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
     */
    public function over(): WindowExpression
    {
        return Expression::over($this->function, $this->arguments);
    }
}
