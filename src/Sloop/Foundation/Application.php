<?php

declare(strict_types=1);

namespace Sloop\Foundation;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
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
use Sloop\Encryption\Cipher;
use Sloop\Encryption\Encryptor;
use Sloop\Encryption\Exception\InvalidConfigException;
use Sloop\Encryption\HexKey;
use Sloop\Encryption\PasswordAlgorithm;
use Sloop\Encryption\PasswordHasher;
use Sloop\Encryption\Signer;
use Sloop\Error\DomainException;
use Sloop\Error\ExceptionHandler;
use Sloop\Error\SloopException;
use Sloop\Http\HttpStatus;
use Sloop\Http\Middleware\MiddlewareDispatcher;
use Sloop\Http\Middleware\SecurityHeadersMiddleware;
use Sloop\Http\Request\FormBody;
use Sloop\Http\Request\JsonBody;
use Sloop\Http\Request\UploadedFiles;
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
        $this->bootEncryption();
        $this->bootSigning();
        $this->bootHashing();
        $this->securityHeaders = $this->bootSecurityHeaders();
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
     * receives its headers (traceparent, CORS, ...). A request whose JSON or
     * form body could not be parsed is answered with a 400 here, before
     * routing, for the same reason.
     *
     * @param  ServerRequestInterface $request PSR-7 server request
     * @return ResponseInterface
     * @throws \RuntimeException      When the response formatter cannot be resolved while rendering an error
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
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
     * @param  ServerRequestInterface $request PSR-7 server request
     * @return ResponseInterface
     * @throws \RuntimeException      When the controller cannot be resolved as an object, parameters are invalid, or controller reflection fails
     * @throws EntryNotFoundException When the controller or a typed dependency cannot be resolved by the container
     * @throws ContainerException     When dependency resolution detects a circular dependency
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
     * @param  ServerRequestInterface|null $serverRequest PSR-7 server request (null = create from globals)
     * @return ResponseInterface
     * @throws \InvalidArgumentException   When no request is given and the one built from the globals is malformed
     * @throws \RuntimeException           When the response formatter cannot be resolved while rendering an error
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
     * @param  \Throwable        $e Uncaught exception propagated past all middleware
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
     * @param  Container  $container DI container for custom factory resolution
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
     * @param  Container               $container    DI container
     * @param  string                  $factoryClass Factory class name
     * @return ChannelFactoryInterface
     * @throws \RuntimeException       If the resolved instance does not implement the interface
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
     * Register the Encryptor from the encryption config, checking the written entries now.
     *
     * A key, previous key or cipher that is written but malformed fails the
     * boot, so a mistyped key surfaces at deploy time. A missing key does not:
     * applications that never encrypt need no key, and resolving Encryptor
     * throws instead.
     *
     * @return void
     * @throws InvalidConfigException If encryption is not an array or has an unsupported key, or encryption.key, encryption.previous_keys or encryption.cipher is malformed
     */
    private function bootEncryption(): void
    {
        $this->assertConfigSection('encryption', ['key', 'previous_keys', 'cipher']);

        $key          = $this->readKey('encryption');
        $previousKeys = $this->readPreviousKeys('encryption');
        $cipher       = $this->readEncryptionCipher();

        if ($key === null) {
            $this->container->singleton(Encryptor::class, static function (): never {
                throw new InvalidConfigException(
                    'The encryption key is not configured. Set encryption.key to 64 hexadecimal characters, for example the output of Encryptor::generateKey().',
                );
            });

            return;
        }

        $this->container->instance(Encryptor::class, new Encryptor($key, $previousKeys, $cipher));
    }

    /**
     * Register the Signer from the signing config, checking the written entries now.
     *
     * The signing key is separate from the encryption key, so a leaked key
     * exposes only one of the two. As with encryption, a written but malformed
     * entry fails the boot and a missing key throws only when Signer is resolved.
     *
     * @return void
     * @throws InvalidConfigException If signing is not an array or has an unsupported key, or signing.key or signing.previous_keys is malformed
     */
    private function bootSigning(): void
    {
        $this->assertConfigSection('signing', ['key', 'previous_keys']);

        $key          = $this->readKey('signing');
        $previousKeys = $this->readPreviousKeys('signing');

        if ($key === null) {
            $this->container->singleton(Signer::class, static function (): never {
                throw new InvalidConfigException(
                    'The signing key is not configured. Set signing.key to 64 hexadecimal characters, for example the output of Signer::generateKey().',
                );
            });

            return;
        }

        $this->container->instance(Signer::class, new Signer($key, $previousKeys));
    }

    /**
     * Read the key entry of a section, treating a missing, null or empty entry as not configured.
     *
     * @param  string                 $section Config section holding the key ('encryption' or 'signing')
     * @return string|null
     * @throws InvalidConfigException If the entry is written but is not a string
     */
    private function readKey(string $section): ?string
    {
        $key = Config::isLoaded() ? Config::get($section . '.key') : null;
        if ($key === null || $key === '') {
            return null;
        }

        if (!\is_string($key)) {
            throw new InvalidConfigException($section . '.key must be a string of 64 hexadecimal characters.');
        }

        return $key;
    }

    /**
     * Read the previous_keys entry of a section, checking that every entry is a key.
     *
     * @param  string                 $section Config section holding the keys ('encryption' or 'signing')
     * @return list<string>
     * @throws InvalidConfigException If the entry is not a list, or one of its keys is not 64 hexadecimal characters
     */
    private function readPreviousKeys(string $section): array
    {
        $previousKeys = (Config::isLoaded() ? Config::get($section . '.previous_keys') : null) ?? [];
        if (!\is_array($previousKeys) || !array_is_list($previousKeys)) {
            throw new InvalidConfigException($section . '.previous_keys must be a list of keys.');
        }

        $checked = [];
        foreach ($previousKeys as $index => $previousKey) {
            if (!\is_string($previousKey) || !HexKey::isValid($previousKey)) {
                throw new InvalidConfigException('Previous ' . $section . ' key #' . $index . ' must be 64 hexadecimal characters (32 bytes).');
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
     * @throws InvalidConfigException If the entry names no supported cipher
     */
    private function readEncryptionCipher(): Cipher
    {
        $name   = (Config::isLoaded() ? Config::get('encryption.cipher') : null) ?? Cipher::Aes256Gcm->value;
        $cipher = \is_string($name) ? Cipher::tryFrom(strtolower($name)) : null;
        if ($cipher === null) {
            throw new InvalidConfigException(
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
     * @throws InvalidConfigException    If security is not an array or has an unsupported key, security.headers is not an array, or one of its entries is not a valid header
     */
    private function bootSecurityHeaders(): SecurityHeadersMiddleware
    {
        $this->assertConfigSection('security', ['headers']);

        $headers = (Config::isLoaded() ? Config::get('security.headers') : null) ?? [];
        if (!\is_array($headers)) {
            throw new InvalidConfigException('security.headers must be an array of header names mapped to a value or null.');
        }

        try {
            return new SecurityHeadersMiddleware($headers);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidConfigException('security.headers: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Register the PasswordHasher from the hashing config, checking every entry now.
     *
     * Missing or null entries take PHP's defaults (bcrypt, cost 12, argon2id
     * 64 MiB / 4 passes / 1 thread), so an application without the config file
     * still gets a working hasher.
     *
     * @return void
     * @throws InvalidConfigException If a section is not an array or has an unsupported key, an entry is malformed, a cost is out of range, or argon2id is chosen and this PHP build lacks it
     */
    private function bootHashing(): void
    {
        $this->assertConfigSection('hashing', ['algorithm', 'bcrypt', 'argon2id']);
        $this->assertConfigSection('hashing.bcrypt', ['cost']);
        $this->assertConfigSection('hashing.argon2id', ['memory', 'time', 'threads']);

        $this->container->instance(PasswordHasher::class, new PasswordHasher(
            algorithm: $this->readHashingAlgorithm(),
            bcryptCost: $this->readHashingInt('hashing.bcrypt.cost', 12),
            argon2Memory: $this->readHashingInt('hashing.argon2id.memory', 65536),
            argon2Time: $this->readHashingInt('hashing.argon2id.time', 4),
            argon2Threads: $this->readHashingInt('hashing.argon2id.threads', 1),
        ));
    }

    /**
     * Check that a config section, when written, is an array with only the given keys.
     *
     * A misspelled key or a value written where a section belongs would
     * otherwise be ignored and the default used without notice.
     *
     * @param  string                 $key         Config key of the section
     * @param  list<string>           $allowedKeys Keys the section may contain
     * @return void
     * @throws InvalidConfigException If the section is not an array or has another key
     */
    private function assertConfigSection(string $key, array $allowedKeys): void
    {
        $section = Config::isLoaded() ? Config::get($key) : null;
        if ($section === null) {
            return;
        }

        if (!\is_array($section)) {
            throw new InvalidConfigException($key . ' must be an array of settings.');
        }

        foreach (array_keys($section) as $name) {
            if (!\in_array($name, $allowedKeys, true)) {
                throw new InvalidConfigException($key . ' has an unsupported key "' . $name . '".');
            }
        }
    }

    /**
     * Read hashing.algorithm, defaulting to bcrypt when it is missing or null.
     *
     * The name is matched without regard to case ('Argon2id' names the same algorithm).
     *
     * @return PasswordAlgorithm
     * @throws InvalidConfigException If the entry names no supported algorithm
     */
    private function readHashingAlgorithm(): PasswordAlgorithm
    {
        $name      = (Config::isLoaded() ? Config::get('hashing.algorithm') : null) ?? PasswordAlgorithm::Bcrypt->value;
        $algorithm = \is_string($name) ? PasswordAlgorithm::tryFrom(strtolower($name)) : null;
        if ($algorithm === null) {
            throw new InvalidConfigException(
                'hashing.algorithm must be one of: ' . implode(', ', array_column(PasswordAlgorithm::cases(), 'value')) . '.',
            );
        }

        return $algorithm;
    }

    /**
     * Read an integer entry of the hashing config, using the default when it is missing or null.
     *
     * @param  string                 $key     Config key
     * @param  int                    $default Value used when the entry is missing or null
     * @return int
     * @throws InvalidConfigException If the entry is written but is not an integer
     */
    private function readHashingInt(string $key, int $default): int
    {
        $value = (Config::isLoaded() ? Config::get($key) : null) ?? $default;
        if (!\is_int($value)) {
            throw new InvalidConfigException($key . ' must be an integer.');
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

        // Piped first so it is the outermost layer: responses that other
        // global middleware return early (a CORS preflight) get the headers too.
        $dispatcher->pipe($this->securityHeaders);

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
     * here, and one PHP refuses to read is marked the same way.
     *
     * @return ServerRequestInterface
     * @throws \InvalidArgumentException When the URI or a header value cannot be parsed, or an uploaded file carries an error code PHP does not define
     */
    private function createServerRequestFromGlobals(): ServerRequestInterface
    {
        $method  = Arr::getString($_SERVER, 'REQUEST_METHOD', 'GET');
        $uri     = Arr::getString($_SERVER, 'REQUEST_URI', '/');
        $headers = \function_exists('getallheaders') ? getallheaders() : [];

        // request_parse_body() finds nothing in php://input once something has
        // read it, so the form body is parsed before the raw body is taken.
        $request = FormBody::parse(
            new ServerRequest($method, $uri, $headers, null, '1.1', $_SERVER)
                ->withQueryParams($_GET)
                ->withParsedBody($_POST)
                ->withCookieParams($_COOKIE)
                ->withUploadedFiles(UploadedFiles::fromGlobals($_FILES)),
            request_parse_body(...),
        );

        $rawBody = file_get_contents('php://input');
        if ($rawBody !== false && $rawBody !== '') {
            $request = $request->withBody(Stream::create($rawBody));
        }

        return JsonBody::parse($request);
    }
}
