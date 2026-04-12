<?php

declare(strict_types=1);

namespace Sloop\Log\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Sloop\Log\TraceContext;

/**
 * Inject the application-supplied extra context values into every log record.
 *
 * Values added via `TraceContext::set()` (user id, tenant id, etc.) are
 * merged into the record's extra array so that structured log output
 * carries them automatically.
 */
final readonly class ExtraContextProcessor implements ProcessorInterface
{
    /**
     * Create a new processor.
     *
     * @param TraceContext $context Trace context providing the extra bag
     */
    public function __construct(private TraceContext $context)
    {
    }

    /**
     * Merge the context extra bag into the record's extra data.
     *
     * Record-level extras take precedence over context extras for the same
     * key, so Monolog processors running after this one can still override.
     *
     * @param  LogRecord $record Incoming log record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra = $record->extra + $this->context->extra;

        return $record;
    }
}
