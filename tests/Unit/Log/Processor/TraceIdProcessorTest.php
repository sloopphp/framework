<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log\Processor;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sloop\Log\Processor\TraceIdProcessor;
use Sloop\Log\TraceContext;

final class TraceIdProcessorTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private function createRecord(array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test message',
            extra: $extra,
        );
    }

    public function testInjectsTraceIdIntoExtra(): void
    {
        $context          = new TraceContext();
        $context->traceId = '0af7651916cd43dd8448eb211c80319c';

        $processor = new TraceIdProcessor($context);
        $record    = $this->createRecord();

        $processed = $processor($record);

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $processed->extra['trace_id']);
    }

    public function testPreservesExistingExtraValues(): void
    {
        $context   = new TraceContext();
        $processor = new TraceIdProcessor($context);
        $record    = $this->createRecord(extra: ['existing' => 'value']);

        $processed = $processor($record);

        $this->assertSame('value', $processed->extra['existing']);
        $this->assertArrayHasKey('trace_id', $processed->extra);
    }

    public function testReflectsContextChangesBetweenInvocations(): void
    {
        $context   = new TraceContext();
        $processor = new TraceIdProcessor($context);

        $context->traceId = '11111111111111111111111111111111';
        $first            = $processor($this->createRecord());

        $context->traceId = '22222222222222222222222222222222';
        $second           = $processor($this->createRecord());

        $this->assertSame('11111111111111111111111111111111', $first->extra['trace_id']);
        $this->assertSame('22222222222222222222222222222222', $second->extra['trace_id']);
    }
}
