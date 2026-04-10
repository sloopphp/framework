<?php

declare(strict_types=1);

namespace Sloop\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware dispatcher.
 *
 * Processes a stack of middleware in FIFO order, delegating to a
 * final request handler when the stack is exhausted.
 */
final class MiddlewareDispatcher implements RequestHandlerInterface
{
    /**
     * Middleware stack to process.
     *
     * @var list<MiddlewareInterface>
     */
    private array $middleware = [];

    /**
     * Current position in the middleware stack.
     *
     * @var int
     */
    private int $index = 0;

    /**
     * The final request handler invoked after all middleware.
     *
     * @var RequestHandlerInterface
     */
    private RequestHandlerInterface $fallbackHandler;

    /**
     * Create a new middleware dispatcher.
     *
     * @param RequestHandlerInterface $fallbackHandler Handler invoked after all middleware
     */
    public function __construct(RequestHandlerInterface $fallbackHandler)
    {
        $this->fallbackHandler = $fallbackHandler;
    }

    /**
     * Add a middleware to the stack.
     *
     * @param MiddlewareInterface $middleware Middleware to add
     * @return self
     */
    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * Handle the request by processing the middleware stack.
     *
     * Each middleware receives the request and a handler that delegates
     * to the next middleware. When the stack is exhausted, the fallback
     * handler is invoked.
     *
     * @param ServerRequestInterface $request Incoming server request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) {
            return $this->fallbackHandler->handle($request);
        }

        $middleware  = $this->middleware[$this->index];
        $nextHandler = clone($this, ['index' => $this->index + 1]);

        return $middleware->process($request, $nextHandler);
    }
}
