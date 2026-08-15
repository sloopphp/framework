<?php

declare(strict_types=1);

namespace Sloop\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/**
 * Helper for asserting that a callable throws a particular exception.
 *
 * Replaces the try/fail/catch shape that would otherwise be repeated in every
 * test that checks an error path. The thrown exception is returned rather than
 * inspected here, so each caller keeps its own assertions on the message, the
 * code, or any exception-specific accessor.
 */
trait ThrowsAssertions
{
    /**
     * Assert that the callable throws the expected exception and return it.
     *
     * Assertion failures raised inside the callable are rethrown untouched.
     * Without that, an `assertSame()` inside a stub callback would be caught
     * here and reported as "the wrong exception was thrown", hiding the real
     * diff.
     *
     * @template T of Throwable
     * @param  class-string<T>   $expected Exception class the callable must throw
     * @param  callable(): mixed $act      Callable expected to throw
     * @return T                 The exception that was thrown
     */
    private function assertThrows(string $expected, callable $act): Throwable
    {
        try {
            $act();
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (Throwable $thrown) {
            self::assertInstanceOf($expected, $thrown);

            return $thrown;
        }

        self::fail('Expected ' . $expected . ' was not thrown.');
    }
}
