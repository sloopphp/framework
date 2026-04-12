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
use Sloop\Http\RouteRequestHandler;
use Sloop\Log\ChannelFactoryInterface;
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Log\Processor\ElapsedTimeProcessor;
use Sloop\Log\Processor\ExtraContextProcessor;
use Sloop\Log\Processor\SpanIdProcessor;
use Sloop\Log\Processor\TraceIdProcessor;
use Sloop\Log\TraceContext;
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

        $finalHandler = new RouteRequestHandler(
            $this->container,
            $route,
            $sloopRequest,
            $params,
            $this->resolveFormatter(),
        );

        if ($route->middleware === []) {
            return $finalHandler->handle($request);
        }

        return $this->buildRouteMiddlewareDispatcher($route, $finalHandler)->handle($request);
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

        $this->container->singleton(TraceContext::class, fn (): TraceContext => new TraceContext());

        $this->container->singleton(
            LogManager::class,
            fn (Container $container): LogManager => $this->createLogManager($container),
        );
    }

    /**
     * Create the LogManager from configuration.
     *
     * @param  Container $container DI container for custom factory resolution
     * @return LogManager
     */
    private function createLogManager(Container $container): LogManager
    {
        $channel  = 'app';
        $channels = [];

        if (Config::isLoaded()) {
            $default = Config::get('log.default');
            if (\is_string($default)) {
                $channel = $default;
            }

            $channels = $this->normalizeLogChannelsFromConfig();
        }

        return new LogManager(
            defaultChannel: $channel,
            channels: $channels,
            customFactoryResolver: fn (string $factoryClass): ChannelFactoryInterface => $this->resolveChannelFactory($container, $factoryClass),
        );
    }

    /**
     * Normalize the log.channels config entry into a typed array.
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizeLogChannelsFromConfig(): array
    {
        $configured = Config::get('log.channels');
        if (!\is_array($configured)) {
            return [];
        }

        $channels = [];
        foreach ($configured as $name => $channelConfig) {
            if (!\is_string($name) || !\is_array($channelConfig)) {
                continue;
            }
            $channels[$name] = $this->filterStringKeys($channelConfig);
        }

        return $channels;
    }

    /**
     * Filter an array to keep only entries with string keys.
     *
     * @param  array<array-key, mixed> $array Array to filter
     * @return array<string, mixed>
     */
    private function filterStringKeys(array $array): array
    {
        return array_filter(
            $array,
            static fn (mixed $_value, int|string $key): bool => \is_string($key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Resolve a ChannelFactoryInterface implementation from the container.
     *
     * @param  Container $container    DI container
     * @param  string    $factoryClass Factory class name
     * @return ChannelFactoryInterface
     * @throws \RuntimeException If the resolved instance does not implement the interface
     */
    private function resolveChannelFactory(Container $container, string $factoryClass): ChannelFactoryInterface
    {
        $factory = $container->get($factoryClass);
        if (!$factory instanceof ChannelFactoryInterface) {
            throw new \RuntimeException(
                'Custom log factory must implement ChannelFactoryInterface: ' . $factoryClass,
            );
        }

        return $factory;
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
     * Initialize the logger and register the framework processors.
     *
     * @return void
     */
    private function bootLog(): void
    {
        $manager = $this->container->get(LogManager::class);
        if (!$manager instanceof LogManager) {
            throw new \RuntimeException('Failed to resolve LogManager from container.');
        }

        $context = $this->container->get(TraceContext::class);
        if (!$context instanceof TraceContext) {
            throw new \RuntimeException('Failed to resolve TraceContext from container.');
        }

        $manager->pushProcessor(new TraceIdProcessor($context));
        $manager->pushProcessor(new SpanIdProcessor($context));
        $manager->pushProcessor(new ElapsedTimeProcessor($context));
        $manager->pushProcessor(new ExtraContextProcessor($context));

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
     * Build a route-level middleware dispatcher.
     *
     * Wraps the given final handler with the middleware stack declared on
     * the route via `Route::middleware()` or group middleware.
     *
     * @param  Route                   $route        Matched route
     * @param  RouteRequestHandler     $finalHandler Handler that invokes the controller
     * @return MiddlewareDispatcher
     * @throws \RuntimeException If a middleware class does not implement MiddlewareInterface
     */
    private function buildRouteMiddlewareDispatcher(Route $route, RouteRequestHandler $finalHandler): MiddlewareDispatcher
    {
        $dispatcher = new MiddlewareDispatcher($finalHandler);

        foreach ($route->middleware as $middlewareClass) {
            $middleware = $this->container->get($middlewareClass);
            if (!$middleware instanceof MiddlewareInterface) {
                throw new \RuntimeException('Middleware must implement MiddlewareInterface: ' . $middlewareClass);
            }

            $dispatcher->pipe($middleware);
        }

        return $dispatcher;
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
