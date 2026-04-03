<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Support\Env;

final class EnvTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
    }

    protected function tearDown(): void
    {
        putenv('SLOOP_TEST_VAR');
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
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Required environment variable 'SLOOP_NONEXISTENT_VAR' is not set.");

        Env::get('SLOOP_NONEXISTENT_VAR', required: true);
    }

    public function testGetThrowsExceptionWhenRequiredAndDefaultBothSpecified(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot specify both 'required: true' and 'default'");

        Env::get('SLOOP_TEST_VAR', default: 'value', required: true);
    }

    // -------------------------------------------------------
    // Immutable
    // -------------------------------------------------------

    public function testImmutableCachesValueAfterEnabled(): void
    {
        putenv('SLOOP_TEST_VAR=original');
        Env::enableImmutable();

        $this->assertSame('original', Env::get('SLOOP_TEST_VAR'));

        putenv('SLOOP_TEST_VAR=changed');

        $this->assertSame('original', Env::get('SLOOP_TEST_VAR'));
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

    // -------------------------------------------------------
    // withEnv
    // -------------------------------------------------------

    public function testWithEnvTemporarilyOverridesVariable(): void
    {
        putenv('SLOOP_TEST_VAR=original');

        $result = Env::withEnv(['SLOOP_TEST_VAR' => 'temporary'], function () {
            return Env::get('SLOOP_TEST_VAR');
        });

        $this->assertSame('temporary', $result);
        $this->assertSame('original', Env::get('SLOOP_TEST_VAR'));
    }

    public function testWithEnvTemporarilyRemovesVariableWithNull(): void
    {
        putenv('SLOOP_TEST_VAR=exists');

        $result = Env::withEnv(['SLOOP_TEST_VAR' => null], function () {
            return Env::get('SLOOP_TEST_VAR');
        });

        $this->assertNull($result);
        $this->assertSame('exists', Env::get('SLOOP_TEST_VAR'));
    }

    public function testWithEnvTemporarilySetsNewVariable(): void
    {
        $result = Env::withEnv(['SLOOP_NEW_VAR' => 'temp'], function () {
            return Env::get('SLOOP_NEW_VAR');
        });

        $this->assertSame('temp', $result);
        $this->assertNull(Env::get('SLOOP_NEW_VAR'));

        putenv('SLOOP_NEW_VAR');
    }

    public function testWithEnvRestoresEnvironmentOnException(): void
    {
        putenv('SLOOP_TEST_VAR=safe');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test error');

        try {
            Env::withEnv(['SLOOP_TEST_VAR' => 'danger'], static function (): never {
                throw new RuntimeException('test error');
            });
        } finally {
            $this->assertSame('safe', Env::get('SLOOP_TEST_VAR'));
        }
    }

    public function testWithEnvOverridesCacheInImmutableMode(): void
    {
        putenv('SLOOP_TEST_VAR=cached');
        Env::enableImmutable();
        Env::get('SLOOP_TEST_VAR'); // cache it

        $result = Env::withEnv(['SLOOP_TEST_VAR' => 'overridden'], function () {
            return Env::get('SLOOP_TEST_VAR');
        });

        $this->assertSame('overridden', $result);
        $this->assertSame('cached', Env::get('SLOOP_TEST_VAR'));
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

        $result = Env::withEnv(['SLOOP_TEST_VAR' => 'inner1'], function () {
            return Env::withEnv(['SLOOP_TEST_VAR' => 'inner2'], function () {
                return Env::get('SLOOP_TEST_VAR');
            });
        });

        $this->assertSame('inner2', $result);
        $this->assertSame('outer', Env::get('SLOOP_TEST_VAR'));
    }
}
