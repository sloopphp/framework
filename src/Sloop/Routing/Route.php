<?php

declare(strict_types=1);

namespace Sloop\Routing;

/**
 * A single route definition.
 *
 * Holds the HTTP method, URI pattern, controller class, action method,
 * optional name, and middleware stack for a route.
 */
final class Route
{
    /**
     * HTTP method (GET, POST, PUT, PATCH, DELETE).
     *
     * @var string
     */
    public readonly string $method;

    /**
     * URI pattern (e.g., '/users/{id}').
     *
     * @var string
     */
    public readonly string $pattern;

    /**
     * Controller class name.
     *
     * @var string
     */
    public readonly string $controller;

    /**
     * Controller action method name.
     *
     * @var string
     */
    public readonly string $action;

    /**
     * Route name for URL generation.
     *
     * @var string|null
     */
    public private(set) ?string $name = null;

    /**
     * Middleware class names for this route.
     *
     * @var list<string>
     */
    public private(set) array $middleware = [];

    /**
     * Compiled regex pattern for matching.
     *
     * @var string|null
     */
    private ?string $regex = null;

    /**
     * Parameter names extracted from the pattern.
     *
     * @var list<string>
     */
    private array $paramNames = [];

    /**
     * Create a new route.
     *
     * @param string $method     HTTP method
     * @param string $pattern    URI pattern
     * @param string $controller Controller class name
     * @param string $action     Action method name
     */
    public function __construct(string $method, string $pattern, string $controller, string $action)
    {
        $this->method     = $method;
        $this->pattern    = $pattern;
        $this->controller = $controller;
        $this->action     = $action;

        $this->compilePattern();
    }

    /**
     * Set the route name.
     *
     * @param  string $name Route name
     * @return self
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Add middleware to this route.
     *
     * @param  string ...$middleware Middleware class names
     * @return self
     */
    public function middleware(string ...$middleware): self
    {
        foreach ($middleware as $m) {
            $this->middleware[] = $m;
        }

        return $this;
    }

    /**
     * Try to match this route against a request method and path.
     *
     * @param string $method Request HTTP method
     * @param string $path   Request URI path
     * @return array<string, string>|null Route parameters, or null if no match
     */
    public function match(string $method, string $path): ?array
    {
        if ($this->method !== $method) {
            return null;
        }

        if ($this->paramNames === []) {
            return $this->pattern === $path ? [] : null;
        }

        if ($this->regex === null || !preg_match($this->regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $i => $name) {
            $params[$name] = $matches[$i + 1];
        }

        return $params;
    }

    /**
     * Compile the URI pattern into a regex and extract parameter names.
     *
     * @return void
     */
    private function compilePattern(): void
    {
        if (!str_contains($this->pattern, '{')) {
            return;
        }

        $regex = preg_replace_callback('/\{(\w+)}/', function (array $matches): string {
            $this->paramNames[] = $matches[1];

            return '([^/]+)';
        }, $this->pattern);

        $this->regex = '#^' . $regex . '$#';
    }
}
