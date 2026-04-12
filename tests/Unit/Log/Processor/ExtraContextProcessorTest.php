<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Log\Processor;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Sloop\Log\Processor\ExtraContextProcessor;
use Sloop\Log\TraceContext;

final class ExtraContextProcessorTest extends TestCase
{
    public function testInjectsContextExtraIntoRecord(): void
    {
        $context = new TraceContext();
        $context->set('user_id', 42);
        $context->set('tenant_id', 'acme');

        $processor = new ExtraContextProcessor($context);
        $record    = $this->createRecord();

        $processed = $processor($record);

        $this->assertSame(42, $processed->extra['user_id']);
        $this->assertSame('acme', $processed->extra['tenant_id']);
    }

    public function testDoesNothingWhenContextExtraIsEmpty(): void
    {
        $context   = new TraceContext();
        $processor = new ExtraContextProcessor($context);
        $record    = $this->createRecord(extra: ['existing' => 'value']);

        $processed = $processor($record);

        $this->assertSame(['existing' => 'value'], $processed->extra);
    }

    public function testRecordLevelExtraTakesPrecedenceOverContext(): void
    {
        $context = new TraceContext();
        $context->set('user_id', 42);

        $processor = new ExtraContextProcessor($context);
        $record    = $this->createRecord(extra: ['user_id' => 99]);

        $processed = $processor($record);

        $this->assertSame(99, $processed->extra['user_id']);
    }

    public function testMergesDisjointKeysFromContextAndRecord(): void
    {
        $context = new TraceContext();
        $context->set('tenant_id', 'acme');

        $processor = new ExtraContextProcessor($context);
        $record    = $this->createRecord(extra: ['request_id' => 'req-1']);

        $processed = $processor($record);

        $this->assertSame('acme', $processed->extra['tenant_id']);
        $this->assertSame('req-1', $processed->extra['request_id']);
    }

    public function testAllowsNullValueInContextExtra(): void
    {
        $context = new TraceContext();
        $context->set('maybe_user_id', null);

        $processor = new ExtraContextProcessor($context);
        $record    = $this->createRecord();

        $processed = $processor($record);

        $this->assertArrayHasKey('maybe_user_id', $processed->extra);
        $this->assertNull($processed->extra['maybe_user_id']);
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
