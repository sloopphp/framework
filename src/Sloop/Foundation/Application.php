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
use Sloop\Container\ContainerException;
use Sloop\Container\EntryNotFoundException;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Factory\ConnectionFactory;
use Sloop\Database\Factory\PdoConnectionFactory;
use Sloop\Database\Replica\ApcuDeadReplicaCache;
use Sloop\Database\Replica\DeadReplicaCache;
use Sloop\Database\Replica\InMemoryDeadReplicaCache;
use Sloop\Database\Replica\RandomReplicaSelector;
use Sloop\Database\Replica\ReplicaSelectorRegistry;
use Sloop\Error\DomainException;
use Sloop\Error\ExceptionHandler;
use Sloop\Error\SloopException;
use Sloop\Http\HttpStatus;
use Sloop\Http\Middleware\MiddlewareDispatcher;
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
     * PHP's `\ReflectionException` (raised when the action method cannot be
     * resolved) is normalized into a `\RuntimeException` so callers do not
     * need to handle Reflection internals.
     *
     * This is also the error boundary: exceptions from route middleware and
     * controllers are converted into error responses here, so the error
     * response still flows back through the global middleware stack and
     * receives its headers (traceparent, CORS, ...).
     *
     * @param  ServerRequestInterface  $request PSR-7 server request
     * @return ResponseInterface
     * @throws \RuntimeException When the response formatter cannot be resolved while rendering an error
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->dispatchRoute($request);
        } catch (\Throwable $e) {
            return $this->renderException($e);
        }
    }

    /**
     * Resolve the route and invoke it through the route middleware chain.
     *
     * @param  ServerRequestInterface  $request PSR-7 server request
     * @return ResponseInterface
     * @throws \RuntimeException       When the controller cannot be resolved as an object, parameters are invalid, or controller reflection fails
     * @throws EntryNotFoundException  When the controller or a typed dependency cannot be resolved by the container
     * @throws ContainerException      When dependency resolution detects a circular dependency
     */
    private function dispatchRoute(ServerRequestInterface $request): ResponseInterface
    {
        $path   = $request->getUri()->getPath();
        $method = $request->getMethod();

        $result = $this->router->resolve($method, $path);
        if ($result === null) {
            return $this->resolveFormatter()->error('Not Found', HttpStatus::NotFound);
        }

        [$route, $params] = $result;

        $finalHandler = new RouteRequestHandler(
            $this->container,
            $route,
            $params,
            $this->resolveFormatter(),
        );

        try {
            if ($route->middleware === []) {
                return $finalHandler->handle($request);
            }

            return $this->buildRouteMiddlewareDispatcher($route, $finalHandler)->handle($request);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException(
                'Failed to reflect controller action: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Process an incoming request through middleware and routing.
     *
     * The primary error boundary lives in handle(), so error responses pass
     * back through the global middleware stack. This catch is a last resort
     * for exceptions thrown by the global middleware themselves.
     *
     * @param ServerRequestInterface|null $serverRequest PSR-7 server request (null = create from globals)
     * @return ResponseInterface
     */
    public function run(?ServerRequestInterface $serverRequest = null): ResponseInterface
    {
        $serverRequest ??= $this->createServerRequestFromGlobals();

        try {
            return $this->buildMiddlewareDispatcher()->handle($serverRequest);
        } catch (\Throwable $e) {
            return $this->renderException($e);
        }
    }

    /**
     * Convert an uncaught exception into an error response.
     *
     * Acts as the outermost error boundary: the ExceptionHandler logs the
     * exception and resolves the HTTP status. Only DomainException messages
     * are client-caused and safe to expose; all other exceptions (including
     * QueryException, whose message embeds the failed SQL) are reduced to a
     * generic message so internals never reach the response body.
     *
     * @param  \Throwable $e Uncaught exception propagated past all middleware
     * @return ResponseInterface
     * @throws \RuntimeException When the response formatter cannot be resolved from the container
     */
    private function renderException(\Throwable $e): ResponseInterface
    {
        try {
            $exceptionHandler = $this->container->get(ExceptionHandler::class);
            if (!$exceptionHandler instanceof ExceptionHandler) {
                throw new \RuntimeException('Failed to resolve ExceptionHandler from container.');
            }

            $status = $exceptionHandler->handle($e);
        } catch (\Throwable) {
            // The ExceptionHandler resolves its log channel lazily, so a broken
            // config/log.php would first surface right here — while handling
            // another exception. Logging failures must not turn a handled error
            // into an unhandled one, so fall back to the status metadata alone.
            $status = $e instanceof SloopException ? $e->statusCode : HttpStatus::InternalServerError;
        }

        $message = $e instanceof DomainException ? $e->getMessage() : 'Server Error';

        return $this->resolveFormatter()->error($message, $status);
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

        $this->container->singleton(ResponseFormatterInterface::class, fn (): ApiResponseFormatter => $this->createResponseFormatter());
        $this->container->singleton(TraceContext::class, fn (): TraceContext => new TraceContext());
        $this->container->singleton(LogManager::class, fn (Container $container): LogManager => $this->createLogManager($container));
        $this->container->singleton(ConnectionFactory::class, fn (): ConnectionFactory => new PdoConnectionFactory());
        $this->container->singleton(
            ReplicaSelectorRegistry::class,
            fn (): ReplicaSelectorRegistry => new ReplicaSelectorRegistry(['random' => new RandomReplicaSelector()]),
        );
        $this->container->singleton(DeadReplicaCache::class, fn (): DeadReplicaCache => $this->createDeadReplicaCache());
        $this->container->singleton(ConnectionManager::class, fn (Container $container): ConnectionManager => $this->createConnectionManager($container));
        $this->container->singleton(ExceptionHandler::class, fn (Container $container): ExceptionHandler => $this->createExceptionHandler($container));
    }

    /**
     * Create the ExceptionHandler wired to the default log channel.
     *
     * @param  Container $container Container holding the LogManager singleton
     * @return ExceptionHandler
     * @throws \RuntimeException      When the LogManager binding resolves to an unexpected type
     * @throws EntryNotFoundException When the LogManager binding cannot be resolved
     * @throws ContainerException     When dependency resolution detects a circular dependency
     */
    private function createExceptionHandler(Container $container): ExceptionHandler
    {
        $logManager = $container->get(LogManager::class);
        if (!$logManager instanceof LogManager) {
            throw new \RuntimeException('Failed to resolve LogManager from container.');
        }

        return new ExceptionHandler($logManager->channel());
    }

    /**
     * Create the DeadReplicaCache implementation appropriate to the current environment.
     *
     * Picks ApcuDeadReplicaCache when ext-apcu is loaded and apc.enable_cli=1
     * (cross-request dead marks); otherwise falls back to InMemoryDeadReplicaCache
     * (per-request dead marks, sufficient as a degraded fallback).
     *
     * @return DeadReplicaCache
     */
    private function createDeadReplicaCache(): DeadReplicaCache
    {
        return ApcuDeadReplicaCache::isAvailable()
            ? new ApcuDeadReplicaCache()
            : new InMemoryDeadReplicaCache();
    }

    /**
     * Create the ApiResponseFormatter from configuration.
     *
     * @return ApiResponseFormatter
     */
    private function createResponseFormatter(): ApiResponseFormatter
    {
        $options = ApiResponseFormatter::DEFAULT_JSON_OPTIONS;
        if (Config::isLoaded()) {
            $configured = Config::get('response.json_options');
            if (\is_int($configured)) {
                $options = $configured;
            }
        }

        return new ApiResponseFormatter($options);
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
     * Create the ConnectionManager from configuration.
     *
     * @param  Container         $container Container used to resolve the ConnectionFactory / ReplicaSelectorRegistry / DeadReplicaCache bindings
     * @return ConnectionManager
     * @throws \RuntimeException If any required binding does not implement its declared interface
     */
    private function createConnectionManager(Container $container): ConnectionManager
    {
        $default     = '';
        $connections = [];

        if (Config::isLoaded()) {
            $configuredDefault = Config::get('database.default');
            if (\is_string($configuredDefault)) {
                $default = $configuredDefault;
            }

            $connections = $this->normalizeDatabaseConnectionsFromConfig();
        }

        $factory = $container->get(ConnectionFactory::class);
        if (!$factory instanceof ConnectionFactory) {
            throw new \RuntimeException(
                'Container binding for ' . ConnectionFactory::class . ' must implement ConnectionFactory.',
            );
        }

        $replicaSelectors = $container->get(ReplicaSelectorRegistry::class);
        if (!$replicaSelectors instanceof ReplicaSelectorRegistry) {
            throw new \RuntimeException(
                'Container binding for ' . ReplicaSelectorRegistry::class . ' must be a ReplicaSelectorRegistry.',
            );
        }

        $deadCache = $container->get(DeadReplicaCache::class);
        if (!$deadCache instanceof DeadReplicaCache) {
            throw new \RuntimeException(
                'Container binding for ' . DeadReplicaCache::class . ' must implement DeadReplicaCache.',
            );
        }

        $logManager = $container->get(LogManager::class);
        if (!$logManager instanceof LogManager) {
            throw new \RuntimeException(
                'Container binding for ' . LogManager::class . ' must be a LogManager.',
            );
        }

        return new ConnectionManager(
            defaultName: $default,
            configs: $connections,
            factory: $factory,
            replicaSelectors: $replicaSelectors,
            deadCache: $deadCache,
            logger: $logManager->channel('database'),
        );
    }

    /**
     * Normalize the database.connections config entry into a typed array.
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizeDatabaseConnectionsFromConfig(): array
    {
        $configured = Config::get('database.connections');
        if (!\is_array($configured)) {
            return [];
        }

        $connections = [];
        foreach ($configured as $name => $connectionConfig) {
            if (!\is_string($name) || !\is_array($connectionConfig)) {
                continue;
            }
            $connections[$name] = $this->filterStringKeys($connectionConfig);
        }

        return $connections;
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
        $headers = \function_exists('getallheaders') ? getallheaders() : [];
        $rawBody = file_get_contents('php://input');
        $body    = $rawBody === false || $rawBody === '' ? null : $rawBody;

        return new ServerRequest($method, $uri, $headers, $body, '1.1', $_SERVER)
            ->withQueryParams($_GET)
            ->withParsedBody($_POST)
            ->withCookieParams($_COOKIE);
    }
}
