<?php

declare(strict_types=1);

namespace Sloop\Database;

/**
 * Per-Connection logging behavior settings.
 *
 * Bundled and injected together with the PSR-3 logger via Connection::setLogger()
 * so query/statement logging respects the pool's privacy and verbosity settings.
 * Failure logging is unconditional (the plan requires `error` records for every
 * failed query); these flags only gate optional output and binding redaction.
 */
final readonly class LoggingOptions
{
    /**
     * Build a logging options bundle.
     *
     * @param bool     $logBindings          When false, prepared-statement bindings are replaced with `[redacted]`
     *                                       in log context to avoid leaking PII or secrets
     * @param bool     $logAllQueries        When true, every query/statement is logged at `debug` level
     * @param int|null $slowQueryThresholdMs When non-null, SELECT-style queries exceeding this many milliseconds
     *                                       are logged at `warning` level. Strict greater-than comparison
     */
    public function __construct(
        public bool $logBindings = true,
        public bool $logAllQueries = false,
        public ?int $slowQueryThresholdMs = null,
    ) {
    }
}
