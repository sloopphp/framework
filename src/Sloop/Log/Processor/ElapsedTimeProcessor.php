<?php

declare(strict_types=1);

namespace Sloop\Log\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Sloop\Log\TraceContext;

/**
 * Inject the elapsed time since request start (in milliseconds) into every
 * log record.
 */
final readonly class ElapsedTimeProcessor implements ProcessorInterface
{
    /**
     * Create a new processor.
     *
     * @param TraceContext $context Trace context providing the request start time
     */
    public function __construct(private TraceContext $context)
    {
    }

    /**
     * Add `elapsed_ms` to the record's extra data.
     *
     * @param  LogRecord $record Incoming log record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['elapsed_ms'] = $this->context->elapsedMs();

        return $record;
    }
}
