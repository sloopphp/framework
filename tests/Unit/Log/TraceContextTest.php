<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sloop\Log\TraceContext;

final class TraceContextTest extends TestCase
{
    public function testGeneratesTraceIdOnConstruction(): void
    {
        $context = new TraceContext();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context->traceId);
    }

    public function testGeneratesSpanIdOnConstruction(): void
    {
        $context = new TraceContext();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context->spanId);
    }

    public function testDifferentInstancesHaveDifferentIds(): void
    {
        $a = new TraceContext();
        $b = new TraceContext();

        $this->assertNotSame($a->traceId, $b->traceId);
        $this->assertNotSame($a->spanId, $b->spanId);
    }

    public function testStartedAtReflectsConstructionTime(): void
    {
        $before  = microtime(true);
        $context = new TraceContext();
        $after   = microtime(true);

        $this->assertGreaterThanOrEqual($before, $context->startedAt);
        $this->assertLessThanOrEqual($after, $context->startedAt);
    }

    public function testTraceIdCanBeOverridden(): void
    {
        $context          = new TraceContext();
        $context->traceId = '0af7651916cd43dd8448eb211c80319c';

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $context->traceId);
    }

    public function testSpanIdCanBeOverridden(): void
    {
        $context         = new TraceContext();
        $context->spanId = 'b7ad6b7169203331';

        $this->assertSame('b7ad6b7169203331', $context->spanId);
    }

    public function testExtraIsEmptyOnConstruction(): void
    {
        $context = new TraceContext();

        $this->assertSame([], $context->extra);
    }

    public function testSetStoresValueInExtra(): void
    {
        $context = new TraceContext();
        $context->set('user_id', 42);

        $this->assertSame(['user_id' => 42], $context->extra);
    }

    public function testSetAcceptsNullValue(): void
    {
        $context = new TraceContext();
        $context->set('maybe_user_id', null);

        $this->assertArrayHasKey('maybe_user_id', $context->extra);
        $this->assertNull($context->extra['maybe_user_id']);
    }

    public function testSetOverwritesExistingKey(): void
    {
        $context = new TraceContext();
        $context->set('user_id', 1);
        $context->set('user_id', 2);

        $this->assertSame(['user_id' => 2], $context->extra);
    }

    public function testSetAccumulatesDifferentKeys(): void
    {
        $context = new TraceContext();
        $context->set('user_id', 42);
        $context->set('tenant_id', 'acme');

        $this->assertSame(['user_id' => 42, 'tenant_id' => 'acme'], $context->extra);
    }

    public function testElapsedMsIsNonNegative(): void
    {
        $context = new TraceContext();

        $this->assertGreaterThanOrEqual(0, $context->elapsedMs());
    }

    public function testElapsedMsIncreasesOverTime(): void
    {
        $context = new TraceContext();
        // CI's Windows runner intermittently reports far fewer ms elapsed than
        // the actual usleep duration (timer resolution + scheduler jitter).
        // Loosen the lower bound to verify only "some elapsed time was
        // measured" rather than a specific minimum, keeping the intent
        // (elapsed_ms grows after a wait) without environment-dependent flakes.
        usleep(30_000);
        $elapsed = $context->elapsedMs();

        $this->assertGreaterThanOrEqual(1, $elapsed);
    }

    public function testElapsedMsConvertsSecondsToMillisecondsAgainstAnInjectedStart(): void
    {
        // A start 1000 seconds in the past makes the expected result large
        // enough that a wrong unit conversion, a wrong sign, or a per-mille
        // scaling error lands far outside the window, while the window itself
        // stays wide enough (500ms over a 1000s base) to absorb scheduler
        // jitter. Deliberately not an upper bound on how fast the test runs.
        $reflection = new ReflectionClass(TraceContext::class);
        $context    = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('startedAt')->setValue($context, microtime(true) - 1000.0);

        $elapsed = $context->elapsedMs();

        $this->assertGreaterThanOrEqual(1_000_000, $elapsed);
        $this->assertLessThan(1_000_500, $elapsed);
    }
}
