<?php

declare(strict_types=1);

namespace Sloop\Log;

use Sloop\Support\Str;

/**
 * Per-request tracing context.
 *
 * Holds the W3C Trace Context identifiers (trace-id, span-id), the request
 * start timestamp, and a free-form extra bag for values that should be
 * injected into every log record. The application bootstraps a single
 * instance per request via the container.
 *
 * The framework never sets service-specific values (user id, session id,
 * etc.). Applications set those through the `set()` method from their own
 * middleware or controllers.
 */
final class TraceContext
{
    /**
     * W3C trace-id (32 hex characters).
     *
     * Overridable from middleware via direct assignment to propagate the
     * upstream trace-id received through the `traceparent` header.
     *
     * @var string
     */
    public string $traceId;

    /**
     * W3C span-id (16 hex characters).
     *
     * @var string
     */
    public string $spanId;

    /**
     * Request start timestamp in seconds (microsecond precision).
     *
     * @var float
     */
    public readonly float $startedAt;

    /**
     * Additional key-value context to inject into log records.
     *
     * @var array<string, mixed>
     */
    public private(set) array $extra = [];

    /**
     * Create a new trace context.
     *
     * Generates an initial trace-id and span-id. The TracingMiddleware
     * overrides these via direct property assignment when a W3C traceparent
     * header is present in the incoming request.
     *
     * @return void
     */
    public function __construct()
    {
        $this->traceId   = Str::randomHex(32);
        $this->spanId    = Str::randomHex(16);
        $this->startedAt = microtime(true);
    }

    /**
     * Set an arbitrary context value for injection into log records.
     *
     * @param  string $key   Context key (used as extra array key)
     * @param  mixed  $value Context value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    /**
     * Get elapsed time since request start in milliseconds.
     *
     * @return int
     */
    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
