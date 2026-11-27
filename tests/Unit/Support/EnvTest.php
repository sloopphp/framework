<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Config\InvalidConfigException;
use Sloop\Support\Env;
use Sloop\Tests\Support\ThrowsAssertions;

final class EnvTest extends TestCase
{
    use ThrowsAssertions;

    protected function setUp(): void
    {
        Env::reset();
    }

    protected function tearDown(): void
    {
        putenv('SLOOP_TEST_VAR');
        putenv('SLOOP_TEST_ABSENT');
        putenv('9876543210');
        Env::reset();
    }

    // -------------------------------------------------------
    // get - basic
    // -------------------------------------------------------

    public function testGetReturnsEnvironmentVariable(): void
    {
        putenv('SLOOP_TEST_VAR=hello');

        $this->assertSame('hello', Env::get('SLOOP_TEST_VAR'));
    }

    public function testGetReturnsNullWhenNotSet(): void
    {
        $this->assertNull(Env::get('SLOOP_NONEXISTENT_VAR'));
    }

    public function testGetReturnsDefaultValue(): void
    {
        $this->assertSame('fallback', Env::get('SLOOP_NONEXISTENT_VAR', default: 'fallback'));
    }

    public function testGetIgnoresDefaultWhenValueExists(): void
    {
        putenv('SLOOP_TEST_VAR=actual');

        $this->assertSame('actual', Env::get('SLOOP_TEST_VAR', default: 'fallback'));
    }

    // -------------------------------------------------------
    // get - required
    // -------------------------------------------------------

    public function testGetRequiredReturnsValueWhenSet(): void
    {
        putenv('SLOOP_TEST_VAR=value');

        $this->assertSame('value', Env::get('SLOOP_TEST_VAR', required: true));
    }

    public function testGetRequiredThrowsExceptionWhenNotSet(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains(
            'Required environment variable \'SLOOP_NONEXISTENT_VAR\' is not set.'
        );

        Env::get('SLOOP_NONEXISTENT_VAR', required: true);
    }

    public function testGetRequiredThrowsExceptionIncludesKeyName(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains(
            'Required environment variable \'ANOTHER_MISSING_KEY\' is not set.'
        );

        Env::get('ANOTHER_MISSING_KEY', required: true);
    }

    public function testGetThrowsExceptionWhenRequiredAndDefaultBothSpecified(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Cannot specify both \'required: true\' and \'default\' for env var \'SLOOP_TEST_VAR\'.'
        );

        Env::get('SLOOP_TEST_VAR', default: 'value', required: true);
    }

    public function testGetThrowsExceptionIncludesKeyInMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Cannot specify both \'required: true\' and \'default\' for env var \'MY_CUSTOM_KEY\'.'
        );

        Env::get('MY_CUSTOM_KEY', default: 'val', required: true);
    }

    // -------------------------------------------------------
    // get - names a request header can set
    // -------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function headerNameProvider(): array
    {
        return [
            'upper case'          => ['HTTP_X_SHADOW'],
            'lower case'          => ['http_x_shadow'],
            'mixed case'          => ['Http_X_Shadow'],
            'prefix only'         => ['HTTP_'],
            'proxy'               => ['HTTP_PROXY'],
            'proxy in lower case' => ['http_proxy'],
        ];
    }

    #[DataProvider('headerNameProvider')]
    public function testGetRejectsANameStartingWithHttpInAnyCase(string $name): void
    {
        putenv($name . '=from-process');

        try {
            $thrown = $this->assertThrows(InvalidArgumentException::class, static fn () => Env::get($name));
        } finally {
            putenv($name);
        }

        $this->assertSame(InvalidArgumentException::class, $thrown::class);
        $this->assertSame(
            'Environment variable \'' . $name . '\' cannot be read with env(): on a web server, a name starting'
            . ' with \'HTTP_\' (in any case) can carry a request header value. Use a name without that prefix,'
            . ' or read it with getenv() where no request is involved.',
            $thrown->getMessage(),
        );
    }

    public function testGetRejectsAHttpNameBeforeCheckingRequiredAndDefault(): void
    {
        $thrown = $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => Env::get('HTTP_X_SHADOW', default: 'value', required: true),
        );

        $this->assertStringStartsWith('Environment variable \'HTTP_X_SHADOW\' cannot be read', $thrown->getMessage());
    }

    public function testGetRejectsAHttpNameInImmutableMode(): void
    {
        putenv('HTTP_X_SHADOW=from-process');

        try {
            Env::enableImmutable();

            $this->expectException(InvalidArgumentException::class);

            Env::get('HTTP_X_SHADOW');
        } finally {
            putenv('HTTP_X_SHADOW');
        }
    }

    public function testGetReadsANameThatOnlyContainsHttp(): void
    {
        putenv('SLOOP_HTTP_X=inner');
        putenv('HTTPX=no-underscore');

        try {
            $inner        = Env::get('SLOOP_HTTP_X');
            $noUnderscore = Env::get('HTTPX');
        } finally {
            putenv('SLOOP_HTTP_X');
            putenv('HTTPX');
        }

        $this->assertSame('inner', $inner);
        $this->assertSame('no-underscore', $noUnderscore);
    }

    public function testEnvHelperRejectsAHttpName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        env('HTTP_X_SHADOW');
    }

    // -------------------------------------------------------
    // Immutable
    // -------------------------------------------------------

    public function testImmutableCachesValueAfterEnabled(): void
    {
        putenv('SLOOP_TEST_VAR=original');
        Env::enableImmutable();

        $beforeChange = Env::get('SLOOP_TEST_VAR');

        putenv('SLOOP_TEST_VAR=changed');

        $afterChange = Env::get('SLOOP_TEST_VAR');

        $this->assertSame('original', $beforeChange);
        $this->assertSame('original', $afterChange);
    }

    public function testMutableModeDoesNotCacheValues(): void
    {
        putenv('SLOOP_TEST_VAR=first');

        $this->assertSame('first', Env::get('SLOOP_TEST_VAR'));

        putenv('SLOOP_TEST_VAR=second');

        $this->assertSame('second', Env::get('SLOOP_TEST_VAR'));
    }

    public function testIsImmutableReturnsCurrentState(): void
    {
        $this->assertFalse(Env::isImmutable());

        Env::enableImmutable();

        $this->assertTrue(Env::isImmutable());
    }

    public function testImmutableFixesAVariableNotReadBeforeItChanges(): void
    {
        putenv('SLOOP_TEST_VAR=at-enable');
        Env::enableImmutable();
        putenv('SLOOP_TEST_VAR=changed');

        $this->assertSame('at-enable', Env::get('SLOOP_TEST_VAR'));
    }

    public function testImmutableKeepsAVariableUnsetAtItsFirstReadUnset(): void
    {
        putenv('SLOOP_TEST_ABSENT');
        Env::enableImmutable();

        $this->assertNull(Env::get('SLOOP_TEST_ABSENT'));

        putenv('SLOOP_TEST_ABSENT=appeared');

        $this->assertNull(Env::get('SLOOP_TEST_ABSENT'));
        $this->assertSame('fallback', Env::get('SLOOP_TEST_ABSENT', 'fallback'));
    }

    public function testImmutableFixesAVariableTheEnvironmentListDoesNotHoldAtItsFirstRead(): void
    {
        putenv('SLOOP_TEST.DOTTED=at-enable');
        try {
            Env::enableImmutable();
            $firstRead = Env::get('SLOOP_TEST.DOTTED');
            putenv('SLOOP_TEST.DOTTED=changed');
            $afterChange = Env::get('SLOOP_TEST.DOTTED');

            $this->assertSame('at-enable', $firstRead);
            $this->assertSame('at-enable', $afterChange);
        } finally {
            putenv('SLOOP_TEST.DOTTED');
        }
    }

    public function testImmutableKeepsAVariableRemovedAfterEnable(): void
    {
        putenv('SLOOP_TEST_VAR=at-enable');
        Env::enableImmutable();
        putenv('SLOOP_TEST_VAR');

        $this->assertSame('at-enable', Env::get('SLOOP_TEST_VAR'));
    }

    public function testEnablingImmutableAgainKeepsTheFirstValues(): void
    {
        putenv('SLOOP_TEST_VAR=first');
        Env::enableImmutable();
        putenv('SLOOP_TEST_VAR=second');
        Env::enableImmutable();

        $this->assertSame('first', Env::get('SLOOP_TEST_VAR'));
    }

    public function testEnablingImmutableAgainDoesNotFixAVariableSetBetweenTheCalls(): void
    {
        putenv('SLOOP_TEST_ABSENT');
        Env::enableImmutable();
        putenv('SLOOP_TEST_ABSENT=between');
        Env::enableImmutable();
        putenv('SLOOP_TEST_ABSENT=at-first-read');

        $this->assertSame('at-first-read', Env::get('SLOOP_TEST_ABSENT'));
    }

    public function testImmutableKeepsAVariableNamedWithDigits(): void
    {
        putenv('9876543210=digits');
        Env::enableImmutable();
        putenv('9876543210=changed');

        $this->assertSame('digits', Env::get('9876543210'));
    }

    public function testImmutableKeepsRequiringAVariableUnsetAtItsFirstRead(): void
    {
        Env::enableImmutable();
        $this->assertThrows(InvalidConfigException::class, static fn () => Env::get('SLOOP_TEST_ABSENT', required: true));
        putenv('SLOOP_TEST_ABSENT=appeared');

        $this->expectException(InvalidConfigException::class);

        Env::get('SLOOP_TEST_ABSENT', required: true);
    }

    // -------------------------------------------------------
    // withEnv
    // -------------------------------------------------------

    public function testWithEnvTemporarilyOverridesVariable(): void
    {
        putenv('SLOOP_TEST_VAR=original');

        $result = Env::withEnv(['SLOOP_TEST_VAR' => 'temporary'], fn () => Env::get('SLOOP_TEST_VAR'));

        $this->assertSame('temporary', $result);
        $this->assertSame('original', Env::get('SLOOP_TEST_VAR'));
    }

    public function testWithEnvSetsVariableRetrievableByGetenv(): void
    {
        Env::withEnv(['SLOOP_PUTENV_TEST' => 'direct_check'], function (): void {
            $this->assertSame('direct_check', getenv('SLOOP_PUTENV_TEST'));
        });

        $this->assertFalse(getenv('SLOOP_PUTENV_TEST'));
    }

    public function testWithEnvTemporarilyRemovesVariableWithNull(): void
    {
        putenv('SLOOP_TEST_VAR=exists');

        $result = Env::withEnv(['SLOOP_TEST_VAR' => null], fn () => Env::get('SLOOP_TEST_VAR'));

        $this->assertNull($result);
        $this->assertSame('exists', Env::get('SLOOP_TEST_VAR'));
    }

    public function testWithEnvTemporarilySetsNewVariable(): void
    {
        $result = Env::withEnv(['SLOOP_NEW_VAR' => 'temp'], fn () => Env::get('SLOOP_NEW_VAR'));

        $this->assertSame('temp', $result);
        $this->assertNull(Env::get('SLOOP_NEW_VAR'));

        putenv('SLOOP_NEW_VAR');
    }

    public function testWithEnvRestoresEnvironmentOnException(): void
    {
        putenv('SLOOP_TEST_VAR=safe');

        $thrown = $this->assertThrows(
            RuntimeException::class,
            static fn (): mixed => Env::withEnv(['SLOOP_TEST_VAR' => 'danger'], static function (): never {
                throw new RuntimeException('test error');
            }),
        );

        $this->assertSame('test error', $thrown->getMessage());
        $this->assertSame('safe', Env::get('SLOOP_TEST_VAR'));
    }

    public function testWithEnvOverridesCacheInImmutableMode(): void
    {
        putenv('SLOOP_TEST_VAR=cached');
        Env::enableImmutable();
        Env::get('SLOOP_TEST_VAR'); // cache it

        $result = Env::withEnv(['SLOOP_TEST_VAR' => 'overridden'], fn () => Env::get('SLOOP_TEST_VAR'));

        $this->assertSame('overridden', $result);
        $this->assertSame('cached', Env::get('SLOOP_TEST_VAR'));
    }

    public function testWithEnvRemovesAndRestoresAVariableInImmutableMode(): void
    {
        putenv('SLOOP_TEST_VAR=fixed');
        Env::enableImmutable();

        $inside = Env::withEnv(['SLOOP_TEST_VAR' => null], fn () => Env::get('SLOOP_TEST_VAR'));

        $this->assertNull($inside);
        $this->assertSame('fixed', Env::get('SLOOP_TEST_VAR'));
    }

    public function testWithEnvSetsAndRemovesAVariableUnsetInImmutableMode(): void
    {
        putenv('SLOOP_TEST_ABSENT');
        Env::enableImmutable();

        $inside = Env::withEnv(['SLOOP_TEST_ABSENT' => 'temporary'], fn () => Env::get('SLOOP_TEST_ABSENT'));

        $this->assertSame('temporary', $inside);
        $this->assertNull(Env::get('SLOOP_TEST_ABSENT'));
    }

    public function testWithEnvInMutableModeLeavesTheVariableUnfixedAfterwards(): void
    {
        putenv('SLOOP_TEST_VAR=original');

        Env::withEnv(['SLOOP_TEST_VAR' => 'overridden'], static fn (): null => null);
        putenv('SLOOP_TEST_VAR=changed');

        $this->assertSame('changed', Env::get('SLOOP_TEST_VAR'));
    }

    public function testWithEnvTakesAVariableNamedWithDigits(): void
    {
        putenv('9876543210=original');

        $inside = Env::withEnv(['9876543210' => 'temporary'], fn () => Env::get('9876543210'));

        $this->assertSame('temporary', $inside);
        $this->assertSame('original', getenv('9876543210'));
        $this->assertSame('original', Env::get('9876543210'));
    }

    public function testWithEnvRemovesAndRestoresAVariableNamedWithDigits(): void
    {
        putenv('9876543210');

        $removed = Env::withEnv(['9876543210' => null], fn () => Env::get('9876543210'));
        $added   = Env::withEnv(['9876543210' => 'temporary'], fn () => Env::get('9876543210'));

        $this->assertNull($removed);
        $this->assertSame('temporary', $added);
        $this->assertFalse(getenv('9876543210'));
    }

    public function testWithEnvSetsAHttpNameForGetenvWhileEnvStillRejectsIt(): void
    {
        $inside = Env::withEnv(['HTTP_X_SHADOW' => 'temporary'], function (): string|false {
            $this->assertThrows(InvalidArgumentException::class, static fn () => Env::get('HTTP_X_SHADOW'));

            return getenv('HTTP_X_SHADOW');
        });

        $this->assertSame('temporary', $inside);
        $this->assertFalse(getenv('HTTP_X_SHADOW'));
    }

    public function testImmutableEnabledInsideWithEnvFixesTheOriginalValues(): void
    {
        putenv('SLOOP_TEST_VAR=original');
        putenv('SLOOP_TEST_ABSENT');

        $inside = Env::withEnv(
            ['SLOOP_TEST_VAR' => 'overridden', 'SLOOP_TEST_ABSENT' => 'temporary'],
            static function (): ?string {
                Env::enableImmutable();

                return Env::get('SLOOP_TEST_VAR');
            },
        );

        $this->assertSame('overridden', $inside);
        $this->assertSame('original', Env::get('SLOOP_TEST_VAR'));
        $this->assertNull(Env::get('SLOOP_TEST_ABSENT'));
    }

    // -------------------------------------------------------
    // reset
    // -------------------------------------------------------

    public function testResetClearsCacheAndImmutableState(): void
    {
        Env::enableImmutable();
        putenv('SLOOP_TEST_VAR=cached');
        Env::get('SLOOP_TEST_VAR');

        Env::reset();

        $this->assertFalse(Env::isImmutable());

        putenv('SLOOP_TEST_VAR=new');
        $this->assertSame('new', Env::get('SLOOP_TEST_VAR'));
    }

    // -------------------------------------------------------
    // Helper function
    // -------------------------------------------------------

    public function testEnvHelperFunction(): void
    {
        putenv('SLOOP_TEST_VAR=helper_test');

        $this->assertSame('helper_test', env('SLOOP_TEST_VAR'));
        $this->assertSame('default', env('SLOOP_NONEXISTENT', default: 'default'));
    }

    // -------------------------------------------------------
    // Boundary values
    // -------------------------------------------------------

    public function testGetReturnsNullForEmptyKey(): void
    {
        $this->assertNull(Env::get(''));
    }

    public function testGetReturnsValueContainingEqualsSign(): void
    {
        putenv('SLOOP_TEST_VAR=foo=bar=baz');

        $this->assertSame('foo=bar=baz', Env::get('SLOOP_TEST_VAR'));
    }

    public function testGetReturnsEmptyStringForEmptyValue(): void
    {
        putenv('SLOOP_TEST_VAR=');

        $this->assertSame('', Env::get('SLOOP_TEST_VAR'));
    }

    public function testGetIgnoresDefaultWhenValueIsEmptyString(): void
    {
        putenv('SLOOP_TEST_VAR=');

        $this->assertSame('', Env::get('SLOOP_TEST_VAR', default: 'fallback'));
    }

    public function testGetRequiredPassesForEmptyStringValue(): void
    {
        putenv('SLOOP_TEST_VAR=');

        $this->assertSame('', Env::get('SLOOP_TEST_VAR', required: true));
    }

    public function testWithEnvSupportsNestedCalls(): void
    {
        putenv('SLOOP_TEST_VAR=outer');

        $result = Env::withEnv(['SLOOP_TEST_VAR' => 'inner1'], fn () => Env::withEnv(['SLOOP_TEST_VAR' => 'inner2'], fn () => Env::get('SLOOP_TEST_VAR')));

        $this->assertSame('inner2', $result);
        $this->assertSame('outer', Env::get('SLOOP_TEST_VAR'));
    }
}
