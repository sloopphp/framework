<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Tests\Support\ThrowsAssertions;

final class ThrowsAssertionsTest extends TestCase
{
    use ThrowsAssertions;

    // The failure paths below are written with an explicit try/catch rather
    // than assertThrows(): the helper rethrows assertion failures by design,
    // so it cannot be used to observe its own.

    public function testReturnsTheThrownExceptionSoCallersCanAssertOnIt(): void
    {
        $thrown = $this->assertThrows(
            RuntimeException::class,
            static fn (): never => throw new RuntimeException('boom', 42),
        );

        self::assertSame('boom', $thrown->getMessage());
        self::assertSame(42, $thrown->getCode());
    }

    public function testAcceptsASubclassOfTheExpectedException(): void
    {
        $thrown = $this->assertThrows(
            RuntimeException::class,
            static fn (): never => throw new OutOfBoundsException('subclass'),
        );

        self::assertInstanceOf(OutOfBoundsException::class, $thrown);
    }

    public function testFailsWhenTheCallableThrowsNothing(): void
    {
        try {
            $this->assertThrows(RuntimeException::class, static fn (): int => 1);
        } catch (AssertionFailedError $failure) {
            self::assertSame('Expected RuntimeException was not thrown.', $failure->getMessage());

            return;
        }

        self::fail('assertThrows accepted a callable that threw nothing.');
    }

    public function testFailsWhenTheCallableThrowsADifferentException(): void
    {
        try {
            $this->assertThrows(
                RuntimeException::class,
                static fn (): never => throw new LogicException('other'),
            );
        } catch (ExpectationFailedException $failure) {
            self::assertStringContainsString(LogicException::class, $failure->getMessage());

            return;
        }

        self::fail('assertThrows accepted the wrong exception class.');
    }

    public function testRethrowsAnAssertionFailureRaisedInsideTheCallable(): void
    {
        // Without the rethrow this failure would be swallowed and reported as
        // "RuntimeException was not thrown", hiding the actual diff.
        try {
            $this->assertThrows(
                RuntimeException::class,
                static function (): string {
                    self::assertSame('expected', 'actual');

                    return 'unreachable';
                },
            );
        } catch (ExpectationFailedException $failure) {
            self::assertStringContainsString('two strings are identical', $failure->getMessage());

            return;
        }

        self::fail('assertThrows swallowed an assertion failure from the callable.');
    }
}
