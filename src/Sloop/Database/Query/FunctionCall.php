<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

use InvalidArgumentException;

/**
 * A function call waiting to be given a window.
 *
 * The named factories on Expression return one of these, and `over()` turns it
 * into the WindowExpression a Grammar compiles. Splitting the call from its
 * window is what lets the two be written in the order they are read:
 * `Expression::sum('price')->over()->partitionBy('status')` names the function
 * first and describes the window after, where the factory form has to hold
 * both at once.
 *
 * Nothing else takes one of these. A call reaches a statement only through
 * `over()`, so a function written without a window is refused where it was
 * written rather than compiling to SQL that means something else.
 *
 * What may stand as an argument is settled by the signatures of the factories
 * that build these, and checked again by WindowExpression when `over()` hands
 * the call on.
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
