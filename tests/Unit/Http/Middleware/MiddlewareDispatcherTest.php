<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Http\Middleware\MiddlewareDispatcher;

final class MiddlewareDispatcherTest extends TestCase
{
    private function createRequest(): ServerRequestInterface
    {
        return new ServerRequest('GET', new Uri('/'));
    }

    private function createFallbackHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200))->withBody(Stream::create('fallback'));
            }
        };
    }

    public function testDispatchesWithNoMiddleware(): void
    {
        $dispatcher = new MiddlewareDispatcher($this->createFallbackHandler());
        $response   = $dispatcher->handle($this->createRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fallback', (string) $response->getBody());
    }

    public function testMiddlewareCanModifyRequest(): void
    {
        $middleware = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $request = $request->withAttribute('modified', true);

                return $handler->handle($request);
            }
        };

        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $body = $request->getAttribute('modified') ? 'modified' : 'original';

                return (new Response(200))->withBody(Stream::create($body));
            }
        };

        $dispatcher = new MiddlewareDispatcher($handler);
        $dispatcher->pipe($middleware);
        $response = $dispatcher->handle($this->createRequest());

        $this->assertSame('modified', (string) $response->getBody());
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $middleware = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return (new Response(403))->withBody(Stream::create('forbidden'));
            }
        };

        $dispatcher = new MiddlewareDispatcher($this->createFallbackHandler());
        $dispatcher->pipe($middleware);
        $response = $dispatcher->handle($this->createRequest());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('forbidden', (string) $response->getBody());
    }

    public function testMiddlewareExecutesInFifoOrder(): void
    {
        $makeMiddleware = function (string $name): MiddlewareInterface {
            return new readonly class ($name) implements MiddlewareInterface {
                public function __construct(
                    private string $name,
                ) {
                }

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    $beforeOrder   = $request->getAttribute('order');
                    $beforeOrder   = \is_array($beforeOrder) ? $beforeOrder : [];
                    $beforeOrder[] = $this->name . ':before';
                    $request       = $request->withAttribute('order', $beforeOrder);

                    $response = $handler->handle($request);

                    $decoded      = json_decode($response->getHeaderLine('X-Order'), true);
                    $afterOrder   = \is_array($decoded) ? $decoded : [];
                    $afterOrder[] = $this->name . ':after';

                    return $response->withHeader('X-Order', (string) json_encode($afterOrder));
                }
            };
        };

        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $order = $request->getAttribute('order');
                $order = \is_array($order) ? $order : [];

                return (new Response(200))->withHeader('X-Order', (string) json_encode($order));
            }
        };

        $dispatcher = new MiddlewareDispatcher($handler);
        $dispatcher->pipe($makeMiddleware('A'));
        $dispatcher->pipe($makeMiddleware('B'));
        $dispatcher->pipe($makeMiddleware('C'));
        $response = $dispatcher->handle($this->createRequest());

        $order = json_decode($response->getHeaderLine('X-Order'), true);
        $this->assertSame(['A:before', 'B:before', 'C:before', 'C:after', 'B:after', 'A:after'], $order);
    }

    public function testMiddlewareCanModifyResponse(): void
    {
        $middleware = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);

                return $response->withHeader('X-Custom', 'added');
            }
        };

        $dispatcher = new MiddlewareDispatcher($this->createFallbackHandler());
        $dispatcher->pipe($middleware);
        $response = $dispatcher->handle($this->createRequest());

        $this->assertSame('added', $response->getHeaderLine('X-Custom'));
        $this->assertSame('fallback', (string) $response->getBody());
    }

    public function testPipeReturnsSelf(): void
    {
        $middleware = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $dispatcher = new MiddlewareDispatcher($this->createFallbackHandler());
        $result     = $dispatcher->pipe($middleware);

        $this->assertSame($dispatcher, $result);
    }

    public function testDispatcherIsReusable(): void
    {
        $dispatcher = new MiddlewareDispatcher($this->createFallbackHandler());
        $response1  = $dispatcher->handle($this->createRequest());
        $response2  = $dispatcher->handle($this->createRequest());

        $this->assertSame(200, $response1->getStatusCode());
        $this->assertSame(200, $response2->getStatusCode());
    }

    public function testDispatcherWithMiddlewareIsReusable(): void
    {
        // Verifies that the dispatcher's index is not mutated by handle()
        // (the clone with new index keeps the original at 0). Critical for
        // long-running workers reusing the same dispatcher instance.
        $middleware = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Pass', 'yes');
            }
        };

        $dispatcher = new MiddlewareDispatcher($this->createFallbackHandler());
        $dispatcher->pipe($middleware);

        $response1 = $dispatcher->handle($this->createRequest());
        $response2 = $dispatcher->handle($this->createRequest());

        $this->assertSame('yes', $response1->getHeaderLine('X-Pass'));
        $this->assertSame('yes', $response2->getHeaderLine('X-Pass'));
    }

    public function testPipeIsChainable(): void
    {
        $a = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-A', '1');
            }
        };
        $b = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-B', '1');
            }
        };

        $dispatcher = (new MiddlewareDispatcher($this->createFallbackHandler()))
            ->pipe($a)
            ->pipe($b);

        $response = $dispatcher->handle($this->createRequest());

        $this->assertSame('1', $response->getHeaderLine('X-A'));
        $this->assertSame('1', $response->getHeaderLine('X-B'));
    }

    public function testMiddlewareExceptionPropagates(): void
    {
        $middleware = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };

        $dispatcher = new MiddlewareDispatcher($this->createFallbackHandler());
        $dispatcher->pipe($middleware);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $dispatcher->handle($this->createRequest());
    }
}
