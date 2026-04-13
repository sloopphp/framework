<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Http\Middleware\TracingMiddleware;
use Sloop\Log\TraceContext;

final class TracingMiddlewareTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function createRequest(array $headers = []): ServerRequestInterface
    {
        return new ServerRequest('GET', '/health', $headers);
    }

    private function createHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    // -------------------------------------------------------
    // traceparent parsing
    // -------------------------------------------------------

    public function testValidTraceparentUpdatesContextTraceId(): void
    {
        $context    = new TraceContext();
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        $middleware->process($request, $this->createHandler());

        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $context->traceId);
    }

    public function testNewSpanIdIsAlwaysGenerated(): void
    {
        $context        = new TraceContext();
        $originalSpanId = $context->spanId;
        $middleware     = new TracingMiddleware($context);
        $request        = $this->createRequest([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        $middleware->process($request, $this->createHandler());

        // span-id is regenerated, not reused from the incoming parent-id
        $this->assertNotSame($originalSpanId, $context->spanId);
        $this->assertNotSame('b7ad6b7169203331', $context->spanId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context->spanId);
    }

    public function testMissingHeaderKeepsInitialContextTraceId(): void
    {
        $context    = new TraceContext();
        $originalId = $context->traceId;
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest();

        $middleware->process($request, $this->createHandler());

        $this->assertSame($originalId, $context->traceId);
    }

    public function testMalformedHeaderFallsBackToGeneratedTraceId(): void
    {
        $context    = new TraceContext();
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest(['traceparent' => 'not-a-valid-header']);

        $middleware->process($request, $this->createHandler());

        // Context keeps its initial (generated) trace-id
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context->traceId);
    }

    public function testVersionFfIsRejected(): void
    {
        $context    = new TraceContext();
        $originalId = $context->traceId;
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest([
            'traceparent' => 'ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        $middleware->process($request, $this->createHandler());

        $this->assertSame($originalId, $context->traceId);
    }

    public function testAllZeroTraceIdIsRejected(): void
    {
        $context    = new TraceContext();
        $originalId = $context->traceId;
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest([
            'traceparent' => '00-00000000000000000000000000000000-b7ad6b7169203331-01',
        ]);

        $middleware->process($request, $this->createHandler());

        $this->assertSame($originalId, $context->traceId);
    }

    public function testAllZeroParentIdIsRejected(): void
    {
        $context    = new TraceContext();
        $originalId = $context->traceId;
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01',
        ]);

        $middleware->process($request, $this->createHandler());

        $this->assertSame($originalId, $context->traceId);
    }

    public function testUppercaseHexIsRejected(): void
    {
        // W3C spec requires lowercase hex characters
        $context    = new TraceContext();
        $originalId = $context->traceId;
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest([
            'traceparent' => '00-0AF7651916CD43DD8448EB211C80319C-B7AD6B7169203331-01',
        ]);

        $middleware->process($request, $this->createHandler());

        $this->assertSame($originalId, $context->traceId);
    }

    // -------------------------------------------------------
    // Response propagation
    // -------------------------------------------------------

    public function testResponseIncludesTraceparentHeader(): void
    {
        $context    = new TraceContext();
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        $response = $middleware->process($request, $this->createHandler());

        $header = $response->getHeaderLine('traceparent');
        $this->assertMatchesRegularExpression(
            '/^00-0af7651916cd43dd8448eb211c80319c-[0-9a-f]{16}-01$/',
            $header,
        );
    }

    public function testResponseTraceparentUsesFreshTraceIdWhenNoHeader(): void
    {
        $context    = new TraceContext();
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest();

        $response = $middleware->process($request, $this->createHandler());

        $header = $response->getHeaderLine('traceparent');
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $header);
    }

    // -------------------------------------------------------
    // tracestate pass-through
    // -------------------------------------------------------

    public function testTracestateHeaderIsPropagatedToResponse(): void
    {
        $context    = new TraceContext();
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'tracestate'  => 'vendor1=value1,vendor2=value2',
        ]);

        $response = $middleware->process($request, $this->createHandler());

        $this->assertSame('vendor1=value1,vendor2=value2', $response->getHeaderLine('tracestate'));
    }

    public function testTracestateIsOmittedWhenNotPresentOnRequest(): void
    {
        $context    = new TraceContext();
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest();

        $response = $middleware->process($request, $this->createHandler());

        $this->assertFalse($response->hasHeader('tracestate'));
    }

    public function testTracestateIsPropagatedEvenWithoutTraceparent(): void
    {
        // tracestate pass-through should not depend on traceparent validity
        $context    = new TraceContext();
        $middleware = new TracingMiddleware($context);
        $request    = $this->createRequest(['tracestate' => 'vendor=value']);

        $response = $middleware->process($request, $this->createHandler());

        $this->assertSame('vendor=value', $response->getHeaderLine('tracestate'));
        // traceparent is still generated from the context
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $response->getHeaderLine('traceparent'));
    }

}
