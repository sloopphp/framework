<?php

declare(strict_types=1);

namespace Sloop\Routing;

/**
 * Fluent builder returned by Router::resource() for customizing CRUD routes.
 *
 * Allows limiting which resource methods are registered
 * via only() or except().
 */
final readonly class RouteGroup
{
    /**
     * Create a new route group.
     *
     * @param list<Route> $routes Routes in this group
     */
    public function __construct(
        public array $routes,
    ) {
    }

    /**
     * Add middleware to all routes in the group.
     *
     * @param  string ...$middleware Middleware class names
     * @return self
     */
    public function middleware(string ...$middleware): self
    {
        foreach ($this->routes as $route) {
            $route->middleware(...$middleware);
        }

        return $this;
    }
}
