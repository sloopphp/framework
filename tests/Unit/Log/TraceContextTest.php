<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
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
        usleep(5_000); // 5ms
        $elapsed = $context->elapsedMs();

        $this->assertGreaterThanOrEqual(5, $elapsed);
    }
}
