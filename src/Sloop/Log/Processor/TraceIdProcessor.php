<?php

declare(strict_types=1);

namespace Sloop\Log\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Sloop\Log\TraceContext;

/**
 * Inject the current trace-id into every log record.
 */
final readonly class TraceIdProcessor implements ProcessorInterface
{
    /**
     * Create a new processor.
     *
     * @param TraceContext $context Trace context providing the current trace-id
     */
    public function __construct(private TraceContext $context)
    {
    }

    /**
     * Add `trace_id` to the record's extra data.
     *
     * @param  LogRecord $record Incoming log record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['trace_id'] = $this->context->traceId;

        return $record;
    }
}
