<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Test stub middleware whose constructor needs an interface the container cannot resolve.
 *
 * Used to verify that Application::buildMiddlewareDispatcher() reports only a
 * wrong middleware class name in config/middleware.php as a config error.
 */
final readonly class MiddlewareWithUnboundDependency implements MiddlewareInterface
{
    public function __construct(private UnboundDependency $dependency)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('dependency', $this->dependency::class));
    }
}
