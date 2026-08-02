<?php

declare(strict_types=1);

namespace Sloop\Routing;

/**
 * Group of routes returned by Router::resource() for attaching middleware.
 *
 * Limiting which resource methods are registered is done via the
 * `only:` / `except:` arguments of Router::resource() itself.
 *
 * @api
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
     * Mutates the contained Route instances in place — the same instances
     * are already registered in the Router, so the middleware takes effect
     * on the registered routes immediately.
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
