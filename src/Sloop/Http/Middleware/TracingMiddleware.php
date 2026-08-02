<?php

declare(strict_types=1);

namespace Sloop\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Log\TraceContext;
use Sloop\Support\Str;

/**
 * W3C Trace Context middleware.
 *
 * Parses the incoming `traceparent` header and updates the shared
 * TraceContext so that downstream log records carry the upstream trace-id.
 * Generates a fresh trace-id if the header is missing or invalid.
 * Always assigns a new span-id for this request and propagates both
 * identifiers back to the client via the response `traceparent` header.
 *
 * The `tracestate` header is passed through unchanged when present so that
 * vendor-specific metadata reaches downstream services.
 *
 * Spec: https://www.w3.org/TR/trace-context/
 */
final readonly class TracingMiddleware implements MiddlewareInterface
{
    /**
     * W3C traceparent format regex.
     *
     * version(2 hex) - trace-id(32 hex) - parent-id(16 hex) - flags(2 hex)
     *
     * @var string
     */
    private const string TRACEPARENT_REGEX = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/';

    /**
     * Trace flag indicating the request is sampled.
     *
     * @var string
     */
    private const string SAMPLED_FLAG = '01';

    /**
     * W3C version used when generating outbound traceparent headers.
     *
     * @var string
     */
    private const string CURRENT_VERSION = '00';

    /**
     * Create a new tracing middleware.
     *
     * @param TraceContext $context Shared trace context updated per request
     */
    public function __construct(private TraceContext $context)
    {
    }

    /**
     * Update the trace context from the incoming traceparent header and
     * propagate the active trace back to the response.
     *
     * @param  ServerRequestInterface  $request Incoming request
     * @param  RequestHandlerInterface $handler Next handler in the stack
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incomingTraceId = $this->extractTraceId($request->getHeaderLine('traceparent'));

        if ($incomingTraceId !== null) {
            $this->context->traceId = $incomingTraceId;
        }

        // Always assign a fresh span-id for this request span.
        $this->context->spanId = Str::randomHex(16);

        $response = $handler->handle($request);

        $response = $response->withHeader(
            'traceparent',
            self::CURRENT_VERSION . '-' . $this->context->traceId . '-' . $this->context->spanId . '-' . self::SAMPLED_FLAG,
        );

        $tracestate = $request->getHeaderLine('tracestate');
        if ($tracestate !== '') {
            $response = $response->withHeader('tracestate', $tracestate);
        }

        return $response;
    }

    /**
     * Extract a valid trace-id from the traceparent header value.
     *
     * Returns null when the header is missing, malformed, uses the
     * unsupported `ff` version, or contains an all-zero trace-id or
     * parent-id (spec-invalid).
     *
     * @param  string $header Raw traceparent header value (may be empty)
     * @return string|null Validated 32-char hex trace-id, or null if invalid
     */
    private function extractTraceId(string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        if (preg_match(self::TRACEPARENT_REGEX, $header, $matches) !== 1) {
            return null;
        }

        [, $version, $traceId, $parentId] = $matches;

        if ($version === 'ff') {
            return null;
        }

        if ($traceId === str_repeat('0', 32)) {
            return null;
        }

        if ($parentId === str_repeat('0', 16)) {
            return null;
        }

        return $traceId;
    }
}
