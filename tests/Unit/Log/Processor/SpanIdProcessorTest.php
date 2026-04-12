<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log\Processor;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sloop\Log\Processor\SpanIdProcessor;
use Sloop\Log\TraceContext;

final class SpanIdProcessorTest extends TestCase
{
    public function testInjectsSpanIdIntoExtra(): void
    {
        $context         = new TraceContext();
        $context->spanId = 'b7ad6b7169203331';

        $processor = new SpanIdProcessor($context);
        $record    = $this->createRecord();

        $processed = $processor($record);

        $this->assertSame('b7ad6b7169203331', $processed->extra['span_id']);
    }

    public function testPreservesExistingExtraValues(): void
    {
        $context   = new TraceContext();
        $processor = new SpanIdProcessor($context);
        $record    = $this->createRecord(extra: ['existing' => 'value']);

        $processed = $processor($record);

        $this->assertSame('value', $processed->extra['existing']);
        $this->assertArrayHasKey('span_id', $processed->extra);
    }

    public function testReflectsContextChangesBetweenInvocations(): void
    {
        $context   = new TraceContext();
        $processor = new SpanIdProcessor($context);

        $context->spanId = '1111111111111111';
        $first           = $processor($this->createRecord());

        $context->spanId = '2222222222222222';
        $second          = $processor($this->createRecord());

        $this->assertSame('1111111111111111', $first->extra['span_id']);
        $this->assertSame('2222222222222222', $second->extra['span_id']);
    }

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
}
