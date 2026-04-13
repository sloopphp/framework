<?php

declare(strict_types=1);

namespace Sloop\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sloop\Container\Container;
use Sloop\Http\Request\Request;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Routing\Route;

/**
 * Final request handler that executes the controller for a resolved route.
 *
 * Used as the innermost handler in the route-middleware dispatcher stack.
 * Resolves the controller from the container, invokes the action with the
 * Sloop Request and route parameters, and wraps non-response return values
 * via the configured response formatter.
 */
final readonly class RouteRequestHandler implements RequestHandlerInterface
{
    /**
     * Create a new route request handler.
     *
     * @param Container                  $container    DI container for controller resolution
     * @param Route                      $route        Resolved route
     * @param Request                    $sloopRequest Sloop request wrapping the PSR-7 request
     * @param array<string, string>      $params       Route parameters
     * @param ResponseFormatterInterface $formatter    Formatter for non-response return values
     */
    public function __construct(
        private Container $container,
        private Route $route,
        private Request $sloopRequest,
        private array $params,
        private ResponseFormatterInterface $formatter,
    ) {
    }

    /**
     * Invoke the controller action and return the response.
     *
     * @param  ServerRequestInterface $request PSR-7 request (unused; route parameters are already bound)
     * @return ResponseInterface
     * @throws RuntimeException If the controller cannot be resolved as an object
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $controller = $this->container->get($this->route->controller);
        if (!\is_object($controller)) {
            throw new RuntimeException('Controller must be an object: ' . $this->route->controller);
        }

        $args = [$this->sloopRequest];
        foreach ($this->params as $value) {
            $args[] = $value;
        }

        $result = $controller->{$this->route->action}(...$args);

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        return $this->formatter->success($result);
    }
}
