<?php

declare(strict_types=1);

namespace Sloop\Log\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Sloop\Log\TraceContext;

/**
 * Inject the current span-id into every log record.
 */
final readonly class SpanIdProcessor implements ProcessorInterface
{
    /**
     * Create a new processor.
     *
     * @param TraceContext $context Trace context providing the current span-id
     */
    public function __construct(private TraceContext $context)
    {
    }

    /**
     * Add `span_id` to the record's extra data.
     *
     * @param  LogRecord $record Incoming log record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['span_id'] = $this->context->spanId;

        return $record;
    }
}
