<?php

declare(strict_types=1);

namespace Sloop\Foundation;

use Nyholm\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sloop\Clock\SystemClock;
use Sloop\Config\Config;
use Sloop\Config\InvalidConfigException;
use Sloop\Container\Container;
use Sloop\Container\ContainerException;
use Sloop\Container\EntryNotFoundException;
use Sloop\Database\Config\ConnectionConfigResolver;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Exception\DatabaseConfigException;
use Sloop\Database\Factory\ConnectionFactory;
use Sloop\Database\Factory\PdoConnectionFactory;
use Sloop\Database\Replica\ApcuDeadReplicaCache;
use Sloop\Database\Replica\DeadReplicaCache;
use Sloop\Database\Replica\InMemoryDeadReplicaCache;
use Sloop\Database\Replica\RandomReplicaSelector;
use Sloop\Database\Replica\ReplicaSelectorRegistry;
use Sloop\Encryption\Cipher;
use Sloop\Encryption\Encryptor;
use Sloop\Encryption\Exception\EncryptionConfigException;
use Sloop\Encryption\HexKey;
use Sloop\Encryption\PasswordAlgorithm;
use Sloop\Encryption\PasswordHasher;
use Sloop\Encryption\Signer;
use Sloop\Error\DomainException;
use Sloop\Error\ExceptionHandler;
use Sloop\Error\SloopException;
use Sloop\Http\HttpStatus;
use Sloop\Http\Middleware\CorsMiddleware;
use Sloop\Http\Middleware\CsrfMiddleware;
use Sloop\Http\Middleware\MiddlewareDispatcher;
use Sloop\Http\Middleware\SecurityHeadersMiddleware;
use Sloop\Http\Request\FormBody;
use Sloop\Http\Request\JsonBody;
use Sloop\Http\Request\RawBody;
use Sloop\Http\Request\UploadedFiles;
use Sloop\Http\Response\ApiResponseFormatter;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Http\RouteRequestHandler;
use Sloop\Log\ChannelFactoryInterface;
use Sloop\Log\Exception\LogConfigException;
use Sloop\Log\Log;
use Sloop\Log\LogConfigValidator;
use Sloop\Log\LogManager;
use Sloop\Log\Processor\ElapsedTimeProcessor;
use Sloop\Log\Processor\ExtraContextProcessor;
use Sloop\Log\Processor\SpanIdProcessor;
use Sloop\Log\Processor\TraceIdProcessor;
use Sloop\Log\TraceContext;
use Sloop\Routing\Route;
use Sloop\Routing\Router;
use Sloop\Security\Exception\SecurityConfigException;
use Sloop\Support\Arr;
use Sloop\Validation\ValidationMessages;

/**
 * Application bootstrap and HTTP request handler.
 *
 * Orchestrates the startup sequence: Path → Config → Container → Log →
 * Middleware → Router → Controller → Response.
 */
final class Application implements RequestHandlerInterface
{
    /**
     * Largest request body read from php://input when security.max_body_size is not set (1 MiB, as nginx).
     *
     * @var int
     */
    private const int DEFAULT_MAX_BODY_SIZE = 1_048_576;

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
     * Security headers middleware, run outside all global middleware.
     *
     * @var SecurityHeadersMiddleware
     */
    private readonly SecurityHeadersMiddleware $securityHeaders;

    /**
     * Largest request body read from php://input, in bytes.
     *
     * @var int<1, max>
     */
    private readonly int $maxBodySize;

    /**
     * JSON encoding flags for the response formatter, from response.json_options.
     *
     * @var int
     */
    private readonly int $jsonOptions;

    /**
     * Default log channel name, from log.default.
     *
     * @var string
     */
    private readonly string $logDefault;

    /**
     * Log channel configs, from log.channels.
     *
     * @var array<string, array<string, mixed>>
     */
    private readonly array $logChannels;

    /**
     * Default database connection name, from database.default ('' when not written).
     *
     * @var string
     */
    private readonly string $databaseDefault;

    /**
     * Database connection configs, from database.connections.
     *
     * @var array<string, array<string, mixed>>
     */
    private readonly array $databaseConnections;

    /**
     * Create and boot the application.
     *
     * @param  string                    $basePath Application root directory
     * @throws InvalidConfigException    If a config file, or one of its entries, is malformed (a subclass of it names the area: DatabaseConfigException, EncryptionConfigException, LogConfigException or SecurityConfigException)
     * @throws \RuntimeException         If the base directory does not exist, or the config directory cannot be scanned
     * @throws \InvalidArgumentException If the base path is empty, or lang/en/validation.php is not a map of known rule names to valid messages
     * @throws \LogicException           If the config or the validation messages were already loaded
     */
    public function __construct(string $basePath)
    {
        Path::init($basePath);

        $this->container = new Container();
        $this->router    = new Router();

        $this->registerCoreBindings();
        $this->loadConfig();
        $this->bootEncryption();
        $this->bootSigning();
        $this->bootHashing();
        $this->securityHeaders = $this->bootSecurityHeaders();
        $this->bootCsrf();
        $this->bootCors();
        $this->maxBodySize         = $this->readMaxBodySize();
        $this->jsonOptions         = $this->readJsonOptions();
        $this->logChannels         = $this->readLogChannels();
        $this->logDefault          = $this->readLogDefault($this->logChannels);
        $this->databaseConnections = $this->readDatabaseConnections();
        $this->databaseDefault     = $this->readDatabaseDefault($this->databaseConnections);
        $this->loadValidationMessages();
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
     * receives its headers (traceparent, CORS, ...). A request whose body is
     * larger than security.max_body_size is answered with a 413 here, and one
     * whose JSON or form body could not be parsed with a 400, before routing,
     * for the same reason. The size is checked first.
     *
     * @param  ServerRequestInterface $request PSR-7 server request
     * @return ResponseInterface
     * @throws \RuntimeException      When the entry registered as the response formatter is not one while rendering an error
     * @throws EntryNotFoundException When the response formatter cannot be resolved by the container while rendering an error
     * @throws ContainerException     When resolving the response formatter detects a circular dependency while rendering an error
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (RawBody::isTooLarge($request)) {
            return $this->renderException(
                new DomainException(
                    'The request body is larger than ' . $this->maxBodySize . ' bytes.',
                    HttpStatus::ContentTooLarge,
                ),
            );
        }

        if (JsonBody::isMalformed($request)) {
            return $this->renderException(
                new DomainException('The request body is not valid JSON.', HttpStatus::BadRequest),
            );
        }

        if (FormBody::isMalformed($request)) {
            return $this->renderException(
                new DomainException('The request body could not be read as form data.', HttpStatus::BadRequest),
            );
        }

        try {
            return $this->dispatchRoute($request);
        } catch (\Throwable $e) {
            return $this->renderException($e);
        }
    }

    /**
     * Resolve the route and invoke it through the route middleware chain.
     *
     * @param  ServerRequestInterface    $request PSR-7 server request
     * @return ResponseInterface
     * @throws DomainException           With status 404 when an int, float or bool route parameter cannot be read as that type
     * @throws \RuntimeException         When the response formatter or a route middleware does not have the expected type, the controller cannot be resolved as an object, the entry registered as the clock is not one, validating a ValidatedRequest fails the way Validator::validate() describes, reflecting the controller action or a ValidatedRequest subclass fails, or invoking its rules() fails
     * @throws \LogicException           When a parameter of the action or of a ValidatedRequest's rules() has a union or intersection type, has no type (for the action, only when another of its parameters has one), or has a builtin type and matches no route parameter while declaring neither a default nor null, a ValidatedRequest subclass the action takes is declared wrongly, or the parsed body is not an array
     * @throws \UnexpectedValueException When a sanitizer closure or a closure given to using() of a ValidatedRequest rule returns the wrong type
     * @throws \InvalidArgumentException When the Validator refuses the rules of a ValidatedRequest subclass, or Rule::date()'s format cannot read back a limit, a default or today's date (see Validator::validate())
     * @throws EntryNotFoundException    When the response formatter, the controller, a route middleware or a typed dependency cannot be resolved by the container
     * @throws ContainerException        When dependency resolution detects a circular dependency
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
     * for exceptions thrown by the global middleware themselves, and for those
     * thrown while the request is built from the globals (a URI that cannot be
     * parsed); the error response it renders still receives the security headers.
     *
     * @param  ServerRequestInterface|null $serverRequest PSR-7 server request (null = create from globals)
     * @return ResponseInterface
     * @throws \RuntimeException           When the entry registered as the response formatter is not one while rendering an error
     * @throws EntryNotFoundException      When the response formatter cannot be resolved by the container while rendering an error
     * @throws ContainerException          When resolving the response formatter detects a circular dependency while rendering an error
     */
    public function run(?ServerRequestInterface $serverRequest = null): ResponseInterface
    {
        try {
            $serverRequest ??= $this->createServerRequestFromGlobals();

            return $this->buildMiddlewareDispatcher()->handle($serverRequest);
        } catch (\Throwable $e) {
            // The security headers middleware does not look at the request,
            // so a placeholder serves when none could be built.
            return $this->withSecurityHeaders($serverRequest ?? new ServerRequest('GET', '/'), $this->renderException($e));
        }
    }

    /**
     * Pass a response made outside the middleware stack through the security headers middleware.
     *
     * @param  ServerRequestInterface $request  Request the response answers
     * @param  ResponseInterface      $response Response to add the headers to
     * @return ResponseInterface
     */
    private function withSecurityHeaders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $handler = new readonly class ($response) implements RequestHandlerInterface {
            /**
             * Create a handler that answers every request with the given response.
             *
             * @param ResponseInterface $response Response to return
             */
            public function __construct(private ResponseInterface $response)
            {
            }

            /**
             * Return the response given to the constructor.
             *
             * @param  ServerRequestInterface $request Incoming request (unused)
             * @return ResponseInterface
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return $this->securityHeaders->process($request, $handler);
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
     * @param  \Throwable             $e Uncaught exception propagated past all middleware
     * @return ResponseInterface
     * @throws \RuntimeException      When the entry registered as the response formatter is not one
     * @throws EntryNotFoundException When the response formatter cannot be resolved by the container
     * @throws ContainerException     When resolving the response formatter detects a circular dependency
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
     * @param  ResponseInterface $response Response to send
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

        $this->container->singleton(ClockInterface::class, static fn (): SystemClock => new SystemClock());
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
     * @param  Container              $container Container holding the LogManager singleton
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
        return new ApiResponseFormatter($this->jsonOptions);
    }

    /**
     * Create the LogManager from configuration.
     *
     * @param  Container  $container DI container for custom factory resolution
     * @return LogManager
     */
    private function createLogManager(Container $container): LogManager
    {
        return new LogManager(
            defaultChannel: $this->logDefault,
            channels: $this->logChannels,
            customFactoryResolver: fn (string $factoryClass): ChannelFactoryInterface => $this->resolveChannelFactory($container, $factoryClass),
        );
    }

    /**
     * Resolve a ChannelFactoryInterface implementation from the container.
     *
     * The class name is the factory entry of a log channel config, so a name
     * the container cannot resolve or a class that is not a factory is a
     * config error. A failure to resolve the factory's own dependencies is a
     * mistake in the bindings, not in the config, and is left to the container.
     *
     * @param  Container               $container    DI container
     * @param  string                  $factoryClass Factory class name
     * @return ChannelFactoryInterface
     * @throws LogConfigException      If the class cannot be resolved or does not implement the interface
     * @throws EntryNotFoundException  If a dependency of the factory cannot be found
     * @throws ContainerException      If resolving the factory's dependencies fails otherwise
     */
    private function resolveChannelFactory(Container $container, string $factoryClass): ChannelFactoryInterface
    {
        if (!$container->has($factoryClass)) {
            throw new LogConfigException('Custom log factory cannot be resolved: ' . $factoryClass);
        }

        $factory = $container->get($factoryClass);
        if (!$factory instanceof ChannelFactoryInterface) {
            throw new LogConfigException(
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
            defaultName: $this->databaseDefault,
            configs: $this->databaseConnections,
            factory: $factory,
            replicaSelectors: $replicaSelectors,
            deadCache: $deadCache,
            logger: $logManager->channel('database'),
        );
    }

    /**
     * Load configuration files if the config directory exists.
     *
     * @return void
     * @throws InvalidConfigException If a config file does not return an array, APP_ENV is not a valid environment name, or an environment override differs in shape from the base
     * @throws \LogicException        If the config is already loaded
     * @throws \RuntimeException      If the config directory cannot be scanned
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
     * Register the Encryptor from the encryption config, checking the written entries now.
     *
     * A key, previous key or cipher that is written but malformed fails the
     * boot, so a mistyped key surfaces at deploy time. A missing key does not:
     * applications that never encrypt need no key, and resolving Encryptor
     * throws instead.
     *
     * @return void
     * @throws EncryptionConfigException If encryption is not an array or has an unsupported key, or encryption.key, encryption.previous_keys or encryption.cipher is malformed
     */
    private function bootEncryption(): void
    {
        $this->assertConfigSection('encryption', ['key', 'previous_keys', 'cipher'], EncryptionConfigException::class);

        $key          = $this->readKey('encryption');
        $previousKeys = $this->readPreviousKeys('encryption');
        $cipher       = $this->readEncryptionCipher();

        if ($key === null) {
            $this->container->singleton(Encryptor::class, static function (): never {
                throw new EncryptionConfigException(
                    'The encryption key is not configured. Set encryption.key to 64 hexadecimal characters, for example the output of Encryptor::generateKey().',
                );
            });

            return;
        }

        try {
            $encryptor = new Encryptor($key, $previousKeys, $cipher);
        } catch (\InvalidArgumentException $e) {
            throw new EncryptionConfigException($e->getMessage(), previous: $e);
        }

        $this->container->instance(Encryptor::class, $encryptor);
    }

    /**
     * Register the Signer from the signing config, checking the written entries now.
     *
     * The signing key is separate from the encryption key, so a leaked key
     * exposes only one of the two. As with encryption, a written but malformed
     * entry fails the boot and a missing key throws only when Signer is resolved.
     *
     * @return void
     * @throws EncryptionConfigException If signing is not an array or has an unsupported key, or signing.key or signing.previous_keys is malformed
     */
    private function bootSigning(): void
    {
        $this->assertConfigSection('signing', ['key', 'previous_keys'], EncryptionConfigException::class);

        $key          = $this->readKey('signing');
        $previousKeys = $this->readPreviousKeys('signing');

        if ($key === null) {
            $this->container->singleton(Signer::class, static function (): never {
                throw new EncryptionConfigException(
                    'The signing key is not configured. Set signing.key to 64 hexadecimal characters, for example the output of Signer::generateKey().',
                );
            });

            return;
        }

        try {
            $signer = new Signer($key, $previousKeys);
        } catch (\InvalidArgumentException $e) {
            throw new EncryptionConfigException($e->getMessage(), previous: $e);
        }

        $this->container->instance(Signer::class, $signer);
    }

    /**
     * Read the key entry of a section, treating a missing, null or empty entry as not configured.
     *
     * @param  string                    $section Config section holding the key ('encryption' or 'signing')
     * @return string|null
     * @throws EncryptionConfigException If the entry is written but is not a string
     */
    private function readKey(string $section): ?string
    {
        $key = Config::isLoaded() ? Config::get($section . '.key') : null;
        if ($key === null || $key === '') {
            return null;
        }

        if (!\is_string($key)) {
            throw new EncryptionConfigException($section . '.key must be a string of 64 hexadecimal characters.');
        }

        return $key;
    }

    /**
     * Read the previous_keys entry of a section, checking that every entry is a key.
     *
     * @param  string                    $section Config section holding the keys ('encryption' or 'signing')
     * @return list<string>
     * @throws EncryptionConfigException If the entry is not a list, or one of its keys is not 64 hexadecimal characters
     */
    private function readPreviousKeys(string $section): array
    {
        $previousKeys = (Config::isLoaded() ? Config::get($section . '.previous_keys') : null) ?? [];
        if (!\is_array($previousKeys) || !array_is_list($previousKeys)) {
            throw new EncryptionConfigException($section . '.previous_keys must be a list of keys.');
        }

        $checked = [];
        foreach ($previousKeys as $index => $previousKey) {
            if (!\is_string($previousKey) || !HexKey::isValid($previousKey)) {
                throw new EncryptionConfigException('Previous ' . $section . ' key #' . $index . ' must be 64 hexadecimal characters (32 bytes).');
            }
            $checked[] = $previousKey;
        }

        return $checked;
    }

    /**
     * Read encryption.cipher, defaulting to AES-256-GCM when it is missing or null.
     *
     * The name is matched without regard to case ('AES-256-GCM' names the same cipher).
     *
     * @return Cipher
     * @throws EncryptionConfigException If the entry names no supported cipher
     */
    private function readEncryptionCipher(): Cipher
    {
        $name   = (Config::isLoaded() ? Config::get('encryption.cipher') : null) ?? Cipher::Aes256Gcm->value;
        $cipher = \is_string($name) ? Cipher::tryFrom(strtolower($name)) : null;
        if ($cipher === null) {
            throw new EncryptionConfigException(
                'encryption.cipher must be one of: ' . implode(', ', array_column(Cipher::cases(), 'value')) . '.',
            );
        }

        return $cipher;
    }

    /**
     * Build the security headers middleware from security.headers, checking every entry now.
     *
     * A malformed entry would otherwise be rejected on every response, so it
     * fails the boot instead. Without the config the default headers are sent.
     *
     * @return SecurityHeadersMiddleware
     * @throws SecurityConfigException   If security is not an array or has an unsupported key, security.headers is not an array, or one of its entries is not a valid header
     */
    private function bootSecurityHeaders(): SecurityHeadersMiddleware
    {
        $this->assertConfigSection('security', ['headers', 'csrf', 'cors', 'max_body_size'], SecurityConfigException::class);

        $headers = (Config::isLoaded() ? Config::get('security.headers') : null) ?? [];
        if (!\is_array($headers)) {
            throw new SecurityConfigException('security.headers must be an array of header names mapped to a value or null.');
        }

        try {
            $middleware = new SecurityHeadersMiddleware($headers);
        } catch (\InvalidArgumentException $e) {
            throw new SecurityConfigException('security.headers: ' . $e->getMessage(), previous: $e);
        }

        // Registered so that listing the class in config/middleware.php or on a
        // route resolves this configured instance, not one with the defaults only.
        $this->container->instance(SecurityHeadersMiddleware::class, $middleware);

        return $middleware;
    }

    /**
     * Register the CSRF middleware built from security.csrf, checking every entry now.
     *
     * The middleware runs only where the application lists it, in
     * config/middleware.php or on a route; both resolve this instance.
     *
     * @return void
     * @throws SecurityConfigException If security.csrf has an unsupported key, origin is not a string or null, trusted_origins is not a list of strings, or an origin is malformed
     */
    private function bootCsrf(): void
    {
        $this->assertConfigSection('security.csrf', ['origin', 'trusted_origins'], SecurityConfigException::class);

        $origin = Config::isLoaded() ? Config::get('security.csrf.origin') : null;
        if ($origin !== null && !\is_string($origin)) {
            throw new SecurityConfigException('security.csrf.origin must be a string or null.');
        }

        $trusted = (Config::isLoaded() ? Config::get('security.csrf.trusted_origins') : null) ?? [];
        if (!\is_array($trusted) || !array_is_list($trusted) || array_filter($trusted, \is_string(...)) !== $trusted) {
            throw new SecurityConfigException('security.csrf.trusted_origins must be a list of origins.');
        }

        try {
            $middleware = new CsrfMiddleware($origin, $trusted);
        } catch (\InvalidArgumentException $e) {
            throw new SecurityConfigException('security.csrf: ' . $e->getMessage(), previous: $e);
        }

        $this->container->instance(CsrfMiddleware::class, $middleware);
    }

    /**
     * Register the CORS middleware built from security.cors, checking every entry now.
     *
     * Listing the middleware in config/middleware.php or on a route resolves
     * this instance, but only the global list sees a preflight: no route
     * matches OPTIONS. An entry that is missing or null keeps the middleware's
     * default.
     *
     * @return void
     * @throws SecurityConfigException If security.cors has an unsupported key, a list entry is not a list of strings, max_age is not an integer, allow_credentials is not a boolean, or the middleware rejects a value
     */
    private function bootCors(): void
    {
        $this->assertConfigSection('security.cors', ['allowed_origins', 'allowed_methods', 'allowed_headers', 'max_age', 'allow_credentials'], SecurityConfigException::class);

        $arguments = [];
        foreach (['allowed_origins' => 'allowedOrigins', 'allowed_methods' => 'allowedMethods', 'allowed_headers' => 'allowedHeaders'] as $key => $parameter) {
            $list = $this->readCorsList($key);
            if ($list !== null) {
                $arguments[$parameter] = $list;
            }
        }

        $maxAge = Config::isLoaded() ? Config::get('security.cors.max_age') : null;
        if ($maxAge !== null && !\is_int($maxAge)) {
            throw new SecurityConfigException('security.cors.max_age must be an integer number of seconds.');
        }

        $credentials = Config::isLoaded() ? Config::get('security.cors.allow_credentials') : null;
        if ($credentials !== null && !\is_bool($credentials)) {
            throw new SecurityConfigException('security.cors.allow_credentials must be true or false.');
        }

        $arguments += array_filter(['maxAge' => $maxAge, 'allowCredentials' => $credentials], static fn (int|bool|null $value): bool => $value !== null);

        try {
            $middleware = new CorsMiddleware(...$arguments);
        } catch (\InvalidArgumentException $e) {
            throw new SecurityConfigException('security.cors: ' . $e->getMessage(), previous: $e);
        }

        $this->container->instance(CorsMiddleware::class, $middleware);
    }

    /**
     * Read one list entry of security.cors.
     *
     * @param  string                  $key Entry name
     * @return list<string>|null       Null when the entry is missing or null
     * @throws SecurityConfigException If the entry is not a list of strings
     */
    private function readCorsList(string $key): ?array
    {
        $list = Config::isLoaded() ? Config::get('security.cors.' . $key) : null;
        if ($list === null) {
            return null;
        }

        if (!\is_array($list) || !array_is_list($list) || array_filter($list, \is_string(...)) !== $list) {
            throw new SecurityConfigException('security.cors.' . $key . ' must be a list of strings.');
        }

        return $list;
    }

    /**
     * Read security.max_body_size, defaulting to 1 MiB when it is missing or null.
     *
     * @return int<1, max>
     * @throws SecurityConfigException If the entry is not a positive integer
     */
    private function readMaxBodySize(): int
    {
        $size = (Config::isLoaded() ? Config::get('security.max_body_size') : null) ?? self::DEFAULT_MAX_BODY_SIZE;
        if (!\is_int($size) || $size < 1) {
            throw new SecurityConfigException('security.max_body_size must be a positive integer number of bytes.');
        }

        return $size;
    }

    /**
     * Read response.json_options, checking the response config now.
     *
     * @return int
     * @throws InvalidConfigException If response is not an array or has an unsupported key, or response.json_options is not an integer
     */
    private function readJsonOptions(): int
    {
        $this->assertConfigSection('response', ['json_options'], InvalidConfigException::class);

        $options = (Config::isLoaded() ? Config::get('response.json_options') : null) ?? ApiResponseFormatter::DEFAULT_JSON_OPTIONS;
        if (!\is_int($options)) {
            throw new InvalidConfigException('response.json_options must be an integer of JSON_* flags.');
        }

        return $options;
    }

    /**
     * Read log.channels, checking the log config and every written channel now.
     *
     * A channel is otherwise read only when it is first used. The factory of
     * a custom channel is still resolved then: the application may bind it
     * in the container after the Application is created.
     *
     * @return array<string, array<string, mixed>>
     * @throws LogConfigException                  If log is not an array or has an unsupported key, or a channel is malformed
     */
    private function readLogChannels(): array
    {
        $this->assertConfigSection('log', ['default', 'channels'], LogConfigException::class);

        return LogConfigValidator::validateChannels(Config::isLoaded() ? Config::get('log.channels') : null);
    }

    /**
     * Read log.default, defaulting to app when it is missing or null.
     *
     * The app channel needs no entry in log.channels when log.default is not
     * written; LogManager writes an unconfigured channel to its default stream.
     *
     * @param  array<string, array<string, mixed>> $channels Checked log.channels
     * @return string
     * @throws LogConfigException                  If log.default is not a string or names a channel log.channels does not define
     */
    private function readLogDefault(array $channels): string
    {
        $default = Config::isLoaded() ? Config::get('log.default') : null;
        if ($default === null) {
            return 'app';
        }

        if (!\is_string($default)) {
            throw new LogConfigException('log.default must be a string.');
        }

        if (!\array_key_exists($default, $channels)) {
            throw new LogConfigException('log.default names the channel "' . $default . '", which log.channels does not define.');
        }

        return $default;
    }

    /**
     * Read database.default, which is '' when it is missing or null.
     *
     * Using the default connection then fails when it is first used.
     *
     * @param  array<string, array<string, mixed>> $connections Checked database.connections
     * @return string
     * @throws DatabaseConfigException             If database.default is not a string or names a connection database.connections does not define
     */
    private function readDatabaseDefault(array $connections): string
    {
        $default = Config::isLoaded() ? Config::get('database.default') : null;
        if ($default === null) {
            return '';
        }

        if (!\is_string($default)) {
            throw new DatabaseConfigException('database.default must be a string.');
        }

        if (!\array_key_exists($default, $connections)) {
            throw new DatabaseConfigException('database.default names the connection "' . $default . '", which database.connections does not define.');
        }

        return $default;
    }

    /**
     * Read database.connections, checking the database config and every connection now.
     *
     * ConnectionManager checks a connection again when it is first used. A
     * replica_selector is checked to be a string here; whether the registry
     * holds that name is checked then, as the application may bind its own
     * registry after the Application is created.
     *
     * @return array<string, array<string, mixed>>
     * @throws DatabaseConfigException             If database is not an array or has an unsupported key, database.connections is not an array of named connections, or a connection is malformed
     */
    private function readDatabaseConnections(): array
    {
        $this->assertConfigSection('database', ['default', 'connections'], DatabaseConfigException::class);

        $configured = (Config::isLoaded() ? Config::get('database.connections') : null) ?? [];
        if (!\is_array($configured)) {
            throw new DatabaseConfigException('database.connections must be an array of connection names mapped to settings.');
        }

        $connections = [];
        foreach ($configured as $name => $config) {
            // Not written, as elsewhere in the config: an environment file
            // can drop a connection by writing null for it.
            if ($config === null) {
                continue;
            }

            if (!\is_string($name)) {
                throw new DatabaseConfigException('database.connections has a connection without a name (key ' . $name . '); write the connection name as the key.');
            }

            if (!\is_array($config)) {
                throw new DatabaseConfigException('database.connections.' . $name . ' must be an array of settings.');
            }

            $typed = [];
            foreach ($config as $key => $value) {
                if (!\is_string($key)) {
                    throw new DatabaseConfigException('database.connections.' . $name . ' has a setting without a name (key ' . $key . ').');
                }
                $typed[$key] = $value;
            }

            ConnectionConfigResolver::validatePool($name, $typed);
            $connections[$name] = $typed;
        }

        return $connections;
    }

    /**
     * Register the PasswordHasher from the hashing config, checking every entry now.
     *
     * Missing or null entries take PHP's defaults (bcrypt, cost 12, argon2id
     * 64 MiB / 4 passes / 1 thread), so an application without the config file
     * still gets a working hasher.
     *
     * @return void
     * @throws EncryptionConfigException If a section is not an array or has an unsupported key, an entry is malformed, a cost is out of range, or argon2id is chosen and this PHP build lacks it
     */
    private function bootHashing(): void
    {
        $this->assertConfigSection('hashing', ['algorithm', 'bcrypt', 'argon2id'], EncryptionConfigException::class);
        $this->assertConfigSection('hashing.bcrypt', ['cost'], EncryptionConfigException::class);
        $this->assertConfigSection('hashing.argon2id', ['memory', 'time', 'threads'], EncryptionConfigException::class);

        $algorithm     = $this->readHashingAlgorithm();
        $bcryptCost    = $this->readHashingInt('hashing.bcrypt.cost', 12);
        $argon2Memory  = $this->readHashingInt('hashing.argon2id.memory', 65536);
        $argon2Time    = $this->readHashingInt('hashing.argon2id.time', 4);
        $argon2Threads = $this->readHashingInt('hashing.argon2id.threads', 1);

        // The entries are read first so that only the constructor's own
        // exception (a cost out of range) is rewrapped. argon2id missing from
        // the build is reported by readHashingAlgorithm() before this point.
        try {
            $hasher = new PasswordHasher($algorithm, $bcryptCost, $argon2Memory, $argon2Time, $argon2Threads);
        } catch (\InvalidArgumentException $e) {
            throw new EncryptionConfigException($e->getMessage(), previous: $e);
        }

        $this->container->instance(PasswordHasher::class, $hasher);
    }

    /**
     * Check that a config section, when written, is an array with only the given keys.
     *
     * A misspelled key or a value written where a section belongs would
     * otherwise be ignored and the default used without notice.
     *
     * @param  string                               $key         Config key of the section
     * @param  list<string>                         $allowedKeys Keys the section may contain
     * @param  class-string<InvalidConfigException> $exception   Exception of the area the section belongs to
     * @return void
     * @throws InvalidConfigException               If the section is not an array or has another key
     */
    private function assertConfigSection(string $key, array $allowedKeys, string $exception): void
    {
        $section = Config::isLoaded() ? Config::get($key) : null;
        if ($section === null) {
            return;
        }

        if (!\is_array($section)) {
            throw new $exception($key . ' must be an array of settings.');
        }

        foreach (array_keys($section) as $name) {
            if (!\in_array($name, $allowedKeys, true)) {
                throw new $exception($key . ' has an unsupported key "' . $name . '".');
            }
        }
    }

    /**
     * Read hashing.algorithm, defaulting to bcrypt when it is missing or null.
     *
     * The name is matched without regard to case ('Argon2id' names the same algorithm).
     *
     * @return PasswordAlgorithm
     * @throws EncryptionConfigException If the entry names no supported algorithm, or names argon2id and this PHP build lacks it
     */
    private function readHashingAlgorithm(): PasswordAlgorithm
    {
        $name      = (Config::isLoaded() ? Config::get('hashing.algorithm') : null) ?? PasswordAlgorithm::Bcrypt->value;
        $algorithm = \is_string($name) ? PasswordAlgorithm::tryFrom(strtolower($name)) : null;
        if ($algorithm === null) {
            throw new EncryptionConfigException(
                'hashing.algorithm must be one of: ' . implode(', ', array_column(PasswordAlgorithm::cases(), 'value')) . '.',
            );
        }

        // Checked here as well as in PasswordHasher, whose RuntimeException
        // for this case would otherwise reach the caller unwrapped.
        if (!$algorithm->isAvailable()) {
            throw new EncryptionConfigException('This PHP build cannot hash with ' . $algorithm->value . '. Choose another hashing.algorithm, or use a PHP built with argon2 support.');
        }

        return $algorithm;
    }

    /**
     * Read an integer entry of the hashing config, using the default when it is missing or null.
     *
     * @param  string                    $key     Config key
     * @param  int                       $default Value used when the entry is missing or null
     * @return int
     * @throws EncryptionConfigException If the entry is written but is not an integer
     */
    private function readHashingInt(string $key, int $default): int
    {
        $value = (Config::isLoaded() ? Config::get($key) : null) ?? $default;
        if (!\is_int($value)) {
            throw new EncryptionConfigException($key . ' must be an integer.');
        }

        return $value;
    }

    /**
     * Load the application's validation message overrides if the lang directory exists.
     *
     * @return void
     * @throws \LogicException           If the messages were already loaded
     * @throws \InvalidArgumentException If lang/en/validation.php is not a map of known rule names to valid messages
     */
    private function loadValidationMessages(): void
    {
        $langPath = Path::base('lang');
        if (is_dir($langPath)) {
            ValidationMessages::load($langPath);
        }
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
     * Load global middleware from config, checking that every entry is a class name.
     *
     * The classes are resolved when the first request is handled: the
     * application may bind them in the container after the Application is created.
     *
     * @return void
     * @throws InvalidConfigException If an entry of config/middleware.php is not a string
     */
    private function loadMiddleware(): void
    {
        $middlewarePath = Path::config('middleware.php');
        if (!file_exists($middlewarePath)) {
            return;
        }

        // Config::load() has already rejected a file that does not return an array.
        $middleware = require $middlewarePath;
        if (\is_array($middleware)) {
            foreach ($middleware as $key => $entry) {
                if (!\is_string($entry)) {
                    throw new InvalidConfigException('config/middleware.php must list middleware class names; the entry at key ' . $key . ' is ' . get_debug_type($entry) . '.');
                }
            }
        }

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
     * The class names come from config/middleware.php, so a name the container
     * cannot resolve or a class that is not a middleware is a config error. A
     * failure to resolve the middleware's own dependencies is left to the
     * container, as for a custom log factory.
     *
     * @return MiddlewareDispatcher
     * @throws InvalidConfigException If an entry of config/middleware.php cannot be resolved or does not implement MiddlewareInterface
     */
    private function buildMiddlewareDispatcher(): MiddlewareDispatcher
    {
        $dispatcher = new MiddlewareDispatcher($this);

        // Piped first so it is the outermost layer: responses that other
        // global middleware return early (a CORS preflight) get the headers too.
        $dispatcher->pipe($this->securityHeaders);

        foreach ($this->middleware as $middlewareClass) {
            if (!$this->container->has($middlewareClass)) {
                throw new InvalidConfigException('Middleware in config/middleware.php cannot be resolved: ' . $middlewareClass);
            }

            $middleware = $this->container->get($middlewareClass);
            if (!$middleware instanceof MiddlewareInterface) {
                throw new InvalidConfigException('Middleware must implement MiddlewareInterface: ' . $middlewareClass);
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
     * @param  Route                $route        Matched route
     * @param  RouteRequestHandler  $finalHandler Handler that invokes the controller
     * @return MiddlewareDispatcher
     * @throws \RuntimeException    If a middleware class does not implement MiddlewareInterface
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
     * @throws \RuntimeException          When the entry registered as ResponseFormatterInterface is not one
     * @throws EntryNotFoundException     When the formatter cannot be resolved by the container
     * @throws ContainerException         When dependency resolution detects a circular dependency
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
     * A body sent with a JSON content type replaces the form values, since PHP
     * puts only form bodies in $_POST; one that is not a JSON object or array
     * is marked, and handle() answers it with a 400. PHP fills $_POST and
     * $_FILES only for POST, so the form body of a PUT, PATCH or DELETE is read
     * here, and one PHP refuses to read is marked the same way. A body larger
     * than security.max_body_size is marked and not attached, and handle()
     * answers it with a 413.
     *
     * @return ServerRequestInterface
     * @throws DomainException           With status 400 when the URI or a header value the client sent cannot be parsed
     * @throws \InvalidArgumentException When an uploaded file carries an error code PHP does not define
     */
    private function createServerRequestFromGlobals(): ServerRequestInterface
    {
        $method  = Arr::getString($_SERVER, 'REQUEST_METHOD', 'GET');
        $uri     = Arr::getString($_SERVER, 'REQUEST_URI', '/');
        $headers = \function_exists('getallheaders') ? getallheaders() : [];

        try {
            $request = new ServerRequest($method, $uri, $headers, null, '1.1', $_SERVER);
        } catch (\InvalidArgumentException $e) {
            // The URI and the headers come from the client.
            throw new DomainException('The request URI or headers could not be read.', HttpStatus::BadRequest, previous: $e);
        }

        // request_parse_body() finds nothing in php://input once something has
        // read it, so the form body is parsed before the raw body is taken.
        $request = FormBody::parse(
            $request
                ->withQueryParams($_GET)
                ->withParsedBody($_POST)
                ->withCookieParams($_COOKIE)
                ->withUploadedFiles(UploadedFiles::fromGlobals($_FILES)),
            request_parse_body(...),
            $this->maxBodySize,
        );

        $request = RawBody::read(
            $request,
            /** @param int<1, max> $maxLength */
            static fn (int $maxLength): string|false => file_get_contents('php://input', false, null, 0, $maxLength),
            $this->maxBodySize,
        );

        return JsonBody::parse($request);
    }
}
