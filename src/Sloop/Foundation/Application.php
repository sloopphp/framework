<?php

declare(strict_types=1);

namespace Sloop\Foundation;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Config\Config;
use Sloop\Container\Container;
use Sloop\Http\HttpStatus;
use Sloop\Http\Middleware\MiddlewareDispatcher;
use Sloop\Http\Request\Request;
use Sloop\Http\Response\ApiResponseFormatter;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Routing\Route;
use Sloop\Routing\Router;
use Sloop\Support\Arr;

/**
 * Application bootstrap and HTTP request handler.
 *
 * Orchestrates the startup sequence: Path → Config → Container → Log →
 * Middleware → Router → Controller → Response.
 */
final class Application implements RequestHandlerInterface
{
    /**
     * DI container.
     *
     * @var Container
     */
    public readonly Container $container;

    /**
     * HTTP router.
     *
     * @var Router
     */
    public readonly Router $router;

    /**
     * Global middleware class names.
     *
     * @var list<string>
     */
    private array $middleware = [];

    /**
     * Create and boot the application.
     *
     * @param string $basePath Application root directory
     */
    public function __construct(string $basePath)
    {
        Path::init($basePath);

        $this->container = new Container();
        $this->router    = new Router();

        $this->registerCoreBindings();
        $this->loadConfig();
        $this->bootLog();
        $this->loadMiddleware();
        $this->loadRoutes();
    }

    /**
     * Handle an HTTP request and produce a response.
     *
     * This is the final request handler invoked after all middleware.
     * It resolves the route, creates the controller via DI, and invokes the action.
     *
     * @param  ServerRequestInterface $request PSR-7 server request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path   = $request->getUri()->getPath();
        $method = $request->getMethod();

        $result = $this->router->resolve($method, $path);
        if ($result === null) {
            return $this->resolveFormatter()->error('Not Found', HttpStatus::NotFound);
        }

        [$route, $params] = $result;
        $sloopRequest     = new Request($request, $params);

        return $this->dispatchRoute($route, $sloopRequest, $params);
    }

    /**
     * Process an incoming request through middleware and routing.
     *
     * @param ServerRequestInterface|null $serverRequest PSR-7 server request (null = create from globals)
     * @return ResponseInterface
     */
    public function run(?ServerRequestInterface $serverRequest = null): ResponseInterface
    {
        $serverRequest ??= $this->createServerRequestFromGlobals();
        $dispatcher      = $this->buildMiddlewareDispatcher();

        return $dispatcher->handle($serverRequest);
    }

    /**
     * Send a PSR-7 response to the client.
     *
     * @param ResponseInterface $response Response to send
     * @return void
     */
    public function send(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }

        echo $response->getBody();
    }

    /**
     * Register core framework bindings in the container.
     *
     * @return void
     */
    private function registerCoreBindings(): void
    {
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(self::class, $this);

        $this->container->singleton(
            ResponseFormatterInterface::class,
            function (): ApiResponseFormatter {
                $options = ApiResponseFormatter::DEFAULT_JSON_OPTIONS;
                if (Config::isLoaded()) {
                    $configured = Config::get('response.json_options');
                    if (\is_int($configured)) {
                        $options = $configured;
                    }
                }

                return new ApiResponseFormatter($options);
            },
        );

        $this->container->singleton(LogManager::class, function (): LogManager {
            $channel = 'app';
            if (Config::isLoaded()) {
                $configured = Config::get('log.channel');
                if (\is_string($configured)) {
                    $channel = $configured;
                }
            }

            return new LogManager($channel);
        });
    }

    /**
     * Load configuration files if the config directory exists.
     *
     * @return void
     */
    private function loadConfig(): void
    {
        $configPath = Path::config();
        if (!is_dir($configPath)) {
            return;
        }

        $environment = getenv('APP_ENV');
        Config::load($configPath, \is_string($environment) ? $environment : null);
    }

    /**
     * Initialize the logger.
     *
     * @return void
     */
    private function bootLog(): void
    {
        $manager = $this->container->get(LogManager::class);
        if (!$manager instanceof LogManager) {
            throw new \RuntimeException('Failed to resolve LogManager from container.');
        }

        Log::init($manager);
    }

    /**
     * Load global middleware from config.
     *
     * @return void
     */
    private function loadMiddleware(): void
    {
        $middlewarePath = Path::config('middleware.php');
        if (!file_exists($middlewarePath)) {
            return;
        }

        $middleware       = require $middlewarePath;
        $this->middleware = Arr::toStringList($middleware);
    }

    /**
     * Load route definitions.
     *
     * @return void
     */
    private function loadRoutes(): void
    {
        $routesPath = Path::routes('api.php');
        if (!file_exists($routesPath)) {
            return;
        }

        $router = $this->router;
        require $routesPath;
    }

    /**
     * Build the middleware dispatcher with global and route middleware.
     *
     * @return MiddlewareDispatcher
     */
    private function buildMiddlewareDispatcher(): MiddlewareDispatcher
    {
        $dispatcher = new MiddlewareDispatcher($this);

        foreach ($this->middleware as $middlewareClass) {
            $middleware = $this->container->get($middlewareClass);
            if (!$middleware instanceof MiddlewareInterface) {
                throw new \RuntimeException('Middleware must implement MiddlewareInterface: ' . $middlewareClass);
            }

            $dispatcher->pipe($middleware);
        }

        return $dispatcher;
    }

    /**
     * Dispatch a resolved route to its controller action.
     *
     * @param Route                 $route  Matched route
     * @param Request               $request Sloop request
     * @param array<string, string> $params Route parameters
     * @return ResponseInterface
     */
    private function dispatchRoute(Route $route, Request $request, array $params): ResponseInterface
    {
        $controller = $this->container->get($route->controller);
        if (!\is_object($controller)) {
            throw new \RuntimeException('Controller must be an object: ' . $route->controller);
        }

        $args = [$request];
        foreach ($params as $value) {
            $args[] = $value;
        }

        $result = $controller->{$route->action}(...$args);

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        return $this->resolveFormatter()->success($result);
    }

    /**
     * Resolve the response formatter from the container.
     *
     * @return ResponseFormatterInterface
     */
    private function resolveFormatter(): ResponseFormatterInterface
    {
        $formatter = $this->container->get(ResponseFormatterInterface::class);
        if (!$formatter instanceof ResponseFormatterInterface) {
            throw new \RuntimeException('Failed to resolve ResponseFormatterInterface from container.');
        }

        return $formatter;
    }

    /**
     * Create a PSR-7 ServerRequest from PHP globals.
     *
     * @return ServerRequestInterface
     */
    private function createServerRequestFromGlobals(): ServerRequestInterface
    {
        $method  = Arr::getString($_SERVER, 'REQUEST_METHOD', 'GET');
        $uri     = Arr::getString($_SERVER, 'REQUEST_URI', '/');
        $headers = \function_exists('getallheaders') ? (array) getallheaders() : [];
        $body    = file_get_contents('php://input') ?: null;

        return (new ServerRequest($method, $uri, $headers, $body, '1.1', $_SERVER))
            ->withQueryParams($_GET)
            ->withParsedBody($_POST)
            ->withCookieParams($_COOKIE);
    }
}
