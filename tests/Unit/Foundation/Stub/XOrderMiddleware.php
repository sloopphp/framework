<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Test middleware that appends 'A' to the X-Order response header.
 *
 * Used together with XOrderMiddlewareB to verify middleware execution ordering.
 */
final class XOrderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $previous = $response->getHeaderLine('X-Order');
        $value    = $previous === '' ? 'A' : $previous . ',A';

        return $response->withHeader('X-Order', $value);
    }
}
