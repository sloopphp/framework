<?php

declare(strict_types=1);

namespace Sloop\Routing;

use RuntimeException;
use Sloop\Support\Arr;

/**
 * HTTP router with file-based route definitions.
 *
 * Supports individual route registration, resource() for CRUD routes,
 * route groups with shared middleware, and named routes.
 */
final class Router
{
    /**
     * Registered routes.
     *
     * @var list<Route>
     */
    public private(set) array $routes = [];

    /**
     * Middleware stack applied to routes registered within a group.
     *
     * @var list<string>
     */
    private array $groupMiddleware = [];

    /**
     * Prefix applied to routes registered within a group.
     *
     * @var string
     */
    private string $groupPrefix = '';

    /**
     * Default resource method to HTTP method/pattern mapping.
     *
     * @var array<string, array{string, string}>
     */
    private const array RESOURCE_MAP = [
        'index'  => ['GET', ''],
        'find'   => ['GET', '/{id}'],
        'create' => ['POST', ''],
        'update' => ['PUT', '/{id}'],
        'delete' => ['DELETE', '/{id}'],
    ];

    /**
     * Register a GET route.
     *
     * @param string $pattern    URI pattern
     * @param string $controller Controller class name
     * @param string $action     Action method name
     * @return Route
     */
    public function get(string $pattern, string $controller, string $action): Route
    {
        return $this->addRoute('GET', $pattern, $controller, $action);
    }

    /**
     * Register a POST route.
     *
     * @param string $pattern    URI pattern
     * @param string $controller Controller class name
     * @param string $action     Action method name
     * @return Route
     */
    public function post(string $pattern, string $controller, string $action): Route
    {
        return $this->addRoute('POST', $pattern, $controller, $action);
    }

    /**
     * Register a PUT route.
     *
     * @param string $pattern    URI pattern
     * @param string $controller Controller class name
     * @param string $action     Action method name
     * @return Route
     */
    public function put(string $pattern, string $controller, string $action): Route
    {
        return $this->addRoute('PUT', $pattern, $controller, $action);
    }

    /**
     * Register a PATCH route.
     *
     * @param string $pattern    URI pattern
     * @param string $controller Controller class name
     * @param string $action     Action method name
     * @return Route
     */
    public function patch(string $pattern, string $controller, string $action): Route
    {
        return $this->addRoute('PATCH', $pattern, $controller, $action);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $pattern    URI pattern
     * @param string $controller Controller class name
     * @param string $action     Action method name
     * @return Route
     */
    public function delete(string $pattern, string $controller, string $action): Route
    {
        return $this->addRoute('DELETE', $pattern, $controller, $action);
    }

    /**
     * Register CRUD resource routes for a controller.
     *
     * Registers index, find, create, update, delete by default.
     * Use only() or except() on the returned RouteGroup to customize.
     *
     * @param string      $pattern    Base URI pattern (e.g., '/users')
     * @param string      $controller Controller class name
     * @param list<string> $only      Only register these methods (empty = all)
     * @param list<string> $except    Exclude these methods
     * @return RouteGroup
     */
    public function resource(
        string $pattern,
        string $controller,
        array $only = [],
        array $except = [],
    ): RouteGroup {
        $methods = array_keys(self::RESOURCE_MAP);

        if ($only !== []) {
            $methods = array_intersect($methods, $only);
        }

        if ($except !== []) {
            $methods = array_diff($methods, $except);
        }

        $baseName = trim($pattern, '/');
        $routes   = [];

        foreach ($methods as $method) {
            [$httpMethod, $suffix] = self::RESOURCE_MAP[$method];
            $route                 = $this->addRoute($httpMethod, $pattern . $suffix, $controller, $method);
            $route->name($baseName . '.' . $method);
            $routes[] = $route;
        }

        return new RouteGroup($routes);
    }

    /**
     * Register routes within a group sharing attributes.
     *
     * @param array<string, mixed> $attributes Group attributes (middleware, prefix)
     * @param callable             $callback   Callback receiving the router
     * @return void
     */
    public function group(array $attributes, callable $callback): void
    {
        $previousMiddleware = $this->groupMiddleware;
        $previousPrefix     = $this->groupPrefix;

        if (isset($attributes['middleware'])) {
            $middleware            = Arr::toStringList($attributes['middleware']);
            $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        }

        if (isset($attributes['prefix']) && \is_string($attributes['prefix'])) {
            $this->groupPrefix .= $attributes['prefix'];
        }

        try {
            $callback($this);
        } finally {
            $this->groupMiddleware = $previousMiddleware;
            $this->groupPrefix     = $previousPrefix;
        }
    }

    /**
     * Resolve a request to a matching route.
     *
     * Static routes are matched before parameterized routes
     * for predictable resolution order.
     *
     * @param string $method HTTP method
     * @param string $path   URI path
     * @return array{Route, array<string, string>}|null Route and params, or null
     */
    public function resolve(string $method, string $path): ?array
    {
        $parameterizedMatch = null;

        foreach ($this->routes as $route) {
            $params = $route->match($method, $path);
            if ($params === null) {
                continue;
            }

            if ($params === []) {
                return [$route, []];
            }

            $parameterizedMatch ??= [$route, $params];
        }

        return $parameterizedMatch;
    }

    /**
     * Find a route by name.
     *
     * @param string $name Route name
     * @return Route
     * @throws RuntimeException If the named route is not found
     */
    public function findByName(string $name): Route
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        throw new RuntimeException('Route not found: ' . $name);
    }

    /**
     * Add a route to the collection.
     *
     * @param string $method     HTTP method
     * @param string $pattern    URI pattern
     * @param string $controller Controller class name
     * @param string $action     Action method name
     * @return Route
     */
    private function addRoute(string $method, string $pattern, string $controller, string $action): Route
    {
        $route = new Route($method, $this->groupPrefix . $pattern, $controller, $action);

        if ($this->groupMiddleware !== []) {
            $route->middleware(...$this->groupMiddleware);
        }

        $this->routes[] = $route;

        return $route;
    }
}
