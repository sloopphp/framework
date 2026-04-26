<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Sloop\Config\Config;
use Sloop\Container\Container;
use Sloop\Database\Connection;
use Sloop\Database\ConnectionFactory;
use Sloop\Database\ConnectionManager;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\PdoConnectionFactory;
use Sloop\Database\ValidatedConfig;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Log\TraceContext;
use Sloop\Routing\Router;
use Sloop\Tests\Support\JsonBodyAssertions;
use Sloop\Tests\Unit\Foundation\Stub\HealthController;
use Sloop\Tests\Unit\Foundation\Stub\InvalidChannelFactory;
use Sloop\Tests\Unit\Foundation\Stub\TestChannelFactory;

/**
 * Integration tests for Application boot sequence and request dispatch.
 *
 * ## Deferred coverage (intentionally not tested in v0.1)
 *
 * - **`run(null)` → `createServerRequestFromGlobals()`**: requires mutating
 *   `$_SERVER` / `$_GET` / `$_POST` superglobals with backup/restore. Test
 *   cost is high vs. value (the method is a thin wrapper around PSR-7
 *   construction). Will be covered by v0.2 integration test framework.
 *
 * - **`send($response)`**: writes HTTP headers via `header()` and outputs
 *   the body via `echo`. Verification requires `@runInSeparateProcess` or
 *   output buffering manipulation. Defer to v0.2 integration tests.
 *
 * - **`resolveFormatter()` / `bootLog()` 解決失敗例外**: requires the
 *   Container to return a non-instance for the registered binding, which is
 *   not reachable through the normal Application boot path. Would need
 *   reflection or container hijacking. Low ROI for v0.1; the runtime
 *   exception is a defensive guard for future extensibility.
 *
 * - **`loadMiddleware()` の非 array 入力**: `Config::load()` validates that
 *   all `/config/*.php` files return arrays before `loadMiddleware()` runs,
 *   so the `Arr::toStringList` non-array fallback is currently dead code.
 *   Kept as forward-compat defense; tested via the mixed-array filter path
 *   instead (see `testLoadMiddlewareFiltersNonStringEntries`).
 */
final class ApplicationTest extends TestCase
{
    use JsonBodyAssertions;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_test_' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');
        mkdir($this->tmpDir . '/routes');

        Path::reset();
        Config::reset();
        Log::reset();
    }

    protected function tearDown(): void
    {
        Path::reset();
        Config::reset();
        Log::reset();

        $this->cleanDir($this->tmpDir);
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->cleanDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function writeConfig(string $filename, string $content): void
    {
        file_put_contents($this->tmpDir . '/config/' . $filename, $content);
    }

    private function writeRoutes(string $content): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', $content);
    }

    private function createRequest(string $method = 'GET', string $uri = '/'): ServerRequest
    {
        return new ServerRequest($method, new Uri($uri));
    }

    public function testBootsWithMinimalStructure(): void
    {
        new Application($this->tmpDir);

        $this->assertTrue(Path::isInitialized());
    }

    public function testLoadsConfigOnBoot(): void
    {
        $this->writeConfig('app.php', '<?php return ["name" => "TestApp"];');
        new Application($this->tmpDir);

        $this->assertTrue(Config::isLoaded());
        $this->assertSame('TestApp', Config::get('app.name'));
    }

    public function testReturns404ForUnknownRoute(): void
    {
        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/nonexistent'));
        $body     = $this->decodeJsonBody($response);
        $error    = $this->narrowToStringArray($body['error']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $error['message']);
    }

    public function testRoutesFileIsLoaded(): void
    {
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));
        $body     = $this->decodeJsonBody($response);
        $data     = $this->narrowToStringArray($body['data']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $data['status']);
    }

    public function testRouteParametersArePassedToController(): void
    {
        $this->writeRoutes('<?php
            $router->get("/users/{id}", \Sloop\Tests\Unit\Foundation\Stub\UserController::class, "find");
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/users/42'));
        $body     = $this->decodeJsonBody($response);
        $data     = $this->narrowToStringArray($body['data']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('42', $data['id']);
    }

    public function testContainerResolvesFormatterInterface(): void
    {
        $app       = new Application($this->tmpDir);
        $formatter = $app->container->get(ResponseFormatterInterface::class);

        $this->assertInstanceOf(ResponseFormatterInterface::class, $formatter);
    }

    public function testRouterStartsEmpty(): void
    {
        $app = new Application($this->tmpDir);

        $this->assertSame([], $app->router->routes);
    }

    public function testControllerReceivesFormatterViaConstructor(): void
    {
        $this->writeRoutes('<?php
            $router->get("/greet", \Sloop\Tests\Unit\Foundation\Stub\GreetController::class, "index");
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/greet'));
        $body     = $this->decodeJsonBody($response);
        $data     = $this->narrowToStringArray($body['data']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $data['message']);
    }

    public function testNonResponseReturnIsAutoWrapped(): void
    {
        $this->writeRoutes('<?php
            $router->get("/raw", \Sloop\Tests\Unit\Foundation\Stub\RawController::class, "data");
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/raw'));
        $body     = $this->decodeJsonBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['key' => 'value', 'method' => 'GET'], $body['data']);
    }

    public function testMiddlewareIsExecutedThroughDispatcher(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/middleware.php',
            '<?php return [\Sloop\Tests\Unit\Foundation\Stub\XRequestIdMiddleware::class];',
        );
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-id', $response->getHeaderLine('X-Request-Id'));
    }

    public function testRouteLevelMiddlewareIsExecuted(): void
    {
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check")
                ->middleware(\Sloop\Tests\Unit\Foundation\Stub\XRequestIdMiddleware::class);
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-id', $response->getHeaderLine('X-Request-Id'));
    }

    public function testRouteGroupMiddlewareIsExecuted(): void
    {
        $this->writeRoutes('<?php
            $router->group(
                ["middleware" => [\Sloop\Tests\Unit\Foundation\Stub\XRequestIdMiddleware::class]],
                function ($router) {
                    $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
                }
            );
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-id', $response->getHeaderLine('X-Request-Id'));
    }

    public function testRouteWithoutMiddlewareIsNotAffected(): void
    {
        // Routes with no middleware should bypass the route dispatcher entirely
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('X-Request-Id'));
    }

    public function testRouteMiddlewareThrowsForInvalidMiddleware(): void
    {
        // HealthController does not implement MiddlewareInterface.
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check")
                ->middleware(\Sloop\Tests\Unit\Foundation\Stub\HealthController::class);
        ');

        $app = new Application($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Middleware must implement MiddlewareInterface: ' . HealthController::class,
        );

        $app->run($this->createRequest('GET', '/health'));
    }

    public function testMultipleRouteMiddlewaresExecuteInRegisteredOrder(): void
    {
        // Route middlewares A and B are registered in that order.
        // MiddlewareDispatcher processes them in FIFO order, so A runs before B.
        // Since each middleware appends its marker after handler->handle() returns,
        // B appears first in the header (inner), then A (outer).
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check")
                ->middleware(
                    \Sloop\Tests\Unit\Foundation\Stub\XOrderMiddleware::class,
                    \Sloop\Tests\Unit\Foundation\Stub\XOrderMiddlewareB::class,
                );
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('B,A', $response->getHeaderLine('X-Order'));
    }

    public function testGlobalAndRouteMiddlewaresCoexistAndExecute(): void
    {
        // Global middleware runs outermost, route middleware runs inside it.
        file_put_contents(
            $this->tmpDir . '/config/middleware.php',
            '<?php return [\Sloop\Tests\Unit\Foundation\Stub\XRequestIdMiddleware::class];',
        );
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check")
                ->middleware(\Sloop\Tests\Unit\Foundation\Stub\XOrderMiddleware::class);
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));

        $this->assertSame(200, $response->getStatusCode());
        // Both middlewares ran
        $this->assertSame('test-id', $response->getHeaderLine('X-Request-Id'));
        $this->assertSame('A', $response->getHeaderLine('X-Order'));
    }

    public function testResourceRouteMiddlewareIsExecuted(): void
    {
        // resource() registers multiple routes; middleware() on the returned
        // RouteGroup should apply to every route in the group.
        $this->writeRoutes('<?php
            $router->resource("/users", \Sloop\Tests\Unit\Foundation\Stub\UserController::class)
                ->middleware(\Sloop\Tests\Unit\Foundation\Stub\XRequestIdMiddleware::class);
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/users/42'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-id', $response->getHeaderLine('X-Request-Id'));
    }

    public function testTracingMiddlewareUpdatesTraceContextFromHeader(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/middleware.php',
            '<?php return [\Sloop\Http\Middleware\TracingMiddleware::class];',
        );
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app     = new Application($this->tmpDir);
        $request = $this->createRequest('GET', '/health')
            ->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

        $response = $app->run($request);

        $this->assertSame(200, $response->getStatusCode());

        // TraceContext reflects the incoming trace-id
        $context = $app->container->get(TraceContext::class);
        $this->assertInstanceOf(TraceContext::class, $context);
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $context->traceId);

        // Response propagates a new traceparent with the incoming trace-id
        $this->assertMatchesRegularExpression(
            '/^00-0af7651916cd43dd8448eb211c80319c-[0-9a-f]{16}-01$/',
            $response->getHeaderLine('traceparent'),
        );
    }

    public function testTracingMiddlewareSyncsTraceIdWithLogProcessor(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/middleware.php',
            '<?php return [\Sloop\Http\Middleware\TracingMiddleware::class];',
        );
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app     = new Application($this->tmpDir);
        $request = $this->createRequest('GET', '/health')
            ->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

        // Replace all handlers so the captured log does not leak to stderr
        // (the default channel writes to php://stderr, which PHPUnit's strict
        // output mode flags as risky under the Infection test runner).
        $handler = new TestHandler();
        Log::monolog()->setHandlers([$handler]);

        $app->run($request);

        // Log after the request to capture the updated trace-id
        Log::channel()->info('after-request');

        $record = $handler->getRecords()[0];
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $record->extra['trace_id']);
    }

    public function testTracingMiddlewareGeneratesFreshSpanIdPerRequest(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/middleware.php',
            '<?php return [\Sloop\Http\Middleware\TracingMiddleware::class];',
        );
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app     = new Application($this->tmpDir);
        $context = $app->container->get(TraceContext::class);
        $this->assertInstanceOf(TraceContext::class, $context);
        $originalSpanId = $context->spanId;

        $app->run($this->createRequest('GET', '/health'));

        // span-id is regenerated by the middleware, not reused from construction
        $this->assertNotSame($originalSpanId, $context->spanId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context->spanId);
    }

    public function testTracingMiddlewarePropagatesTracestate(): void
    {
        file_put_contents(
            $this->tmpDir . '/config/middleware.php',
            '<?php return [\Sloop\Http\Middleware\TracingMiddleware::class];',
        );
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app     = new Application($this->tmpDir);
        $request = $this->createRequest('GET', '/health')
            ->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01')
            ->withHeader('tracestate', 'vendor1=value1,vendor2=value2');

        $response = $app->run($request);

        $this->assertSame('vendor1=value1,vendor2=value2', $response->getHeaderLine('tracestate'));
    }

    public function testLoadMiddlewareFiltersNonStringEntries(): void
    {
        // When middleware.php contains mixed types, Arr::toStringList filters non-strings
        // silently. Valid middleware classes still execute.
        // Note: Config::load() validates that the file returns an array, so a non-array
        // value would throw earlier; only mixed-type arrays are reachable here.
        file_put_contents(
            $this->tmpDir . '/config/middleware.php',
            '<?php return [\Sloop\Tests\Unit\Foundation\Stub\XRequestIdMiddleware::class, 42, null];',
        );
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-id', $response->getHeaderLine('X-Request-Id'));
    }

    public function testConfigJsonOptionsAreInjected(): void
    {
        $this->writeConfig('response.php', '<?php return ["json_options" => JSON_PRETTY_PRINT];');
        $this->writeRoutes('<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

        $app      = new Application($this->tmpDir);
        $response = $app->run($this->createRequest('GET', '/health'));
        $body     = (string) $response->getBody();

        // JSON_PRETTY_PRINT adds newlines and 4-space indentation
        $this->assertStringContainsString("\n", $body);
        $this->assertStringContainsString('    ', $body);
    }

    public function testConfigLogDefaultChannelIsInjected(): void
    {
        $this->writeConfig('log.php', '<?php return ["default" => "custom"];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);

        $this->assertInstanceOf(LogManager::class, $manager);
        $this->assertSame('custom', $manager->defaultChannel);
    }

    public function testConfigLogChannelsAreInjected(): void
    {
        $this->writeConfig('log.php', '<?php return [
            "default" => "app",
            "channels" => [
                "app" => [
                    "driver" => "stream",
                    "stream" => "php://stderr",
                    "level" => "warning",
                ],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);

        $this->assertInstanceOf(LogManager::class, $manager);

        $handler = $manager->channel('app')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Warning, $handler->getLevel());
    }

    public function testConfigLogChannelsSkipsInvalidEntries(): void
    {
        // Invalid entries are in the middle to verify `continue` (not `break`):
        // audit must still be registered after the invalid entries are skipped.
        $this->writeConfig('log.php', '<?php return [
            "default" => "app",
            "channels" => [
                "app" => ["driver" => "stream", "level" => "debug"],
                0 => ["driver" => "stream"],
                "invalid_scalar" => "not_an_array",
                "audit" => ["driver" => "stream", "level" => "warning"],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);

        $this->assertInstanceOf(LogManager::class, $manager);

        // Both valid channels must be registered independently
        $appHandler = $manager->channel('app')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $appHandler);
        $this->assertSame(Level::Debug, $appHandler->getLevel());

        $auditHandler = $manager->channel('audit')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $auditHandler);
        $this->assertSame(Level::Warning, $auditHandler->getLevel());
    }

    public function testConfigLogChannelsFiltersNumericKeysInsideChannel(): void
    {
        $this->writeConfig('log.php', '<?php return [
            "default" => "app",
            "channels" => [
                "app" => [
                    "driver" => "stream",
                    "level" => "error",
                    0 => "ignored_numeric_key",
                ],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);

        // Numeric keys inside channel config must not corrupt string-keyed config
        $handler = $manager->channel('app')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $handler);
        $this->assertSame(Level::Error, $handler->getLevel());
    }

    public function testConfigLogDefaultFallsBackWhenNotString(): void
    {
        $this->writeConfig('log.php', '<?php return ["default" => 42];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);

        $this->assertInstanceOf(LogManager::class, $manager);
        $this->assertSame('app', $manager->defaultChannel);
    }

    public function testConfigLogChannelsIgnoredWhenNotArray(): void
    {
        $this->writeConfig('log.php', '<?php return ["channels" => "not_an_array"];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);

        // Falls back to default auto-created StreamHandler (unconfigured path)
        $this->assertInstanceOf(LogManager::class, $manager);
        $handler = $manager->channel('app')->getHandlers()[0];
        $this->assertInstanceOf(StreamHandler::class, $handler);
    }

    public function testConfigLogCustomDriverResolvesFactoryFromContainer(): void
    {
        $this->writeConfig(
            'log.php',
            '<?php return [
                "default" => "custom_channel",
                "channels" => [
                    "custom_channel" => [
                        "driver" => "custom",
                        "factory" => \\' . TestChannelFactory::class . '::class,
                    ],
                ],
            ];',
        );

        $app    = new Application($this->tmpDir);
        $logger = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $logger);

        $channel = $logger->channel('custom_channel');
        $this->assertSame('custom_channel', $channel->getName());
        $this->assertInstanceOf(TestHandler::class, $channel->getHandlers()[0]);
    }

    public function testConfigLogCustomDriverThrowsWhenFactoryDoesNotImplementInterface(): void
    {
        $this->writeConfig(
            'log.php',
            '<?php return [
                "channels" => [
                    "bad" => [
                        "driver" => "custom",
                        "factory" => \\' . InvalidChannelFactory::class . '::class,
                    ],
                ],
            ];',
        );

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Custom log factory must implement ChannelFactoryInterface: ' . InvalidChannelFactory::class);

        $manager->channel('bad');
    }

    public function testContainerRegistersItself(): void
    {
        $app       = new Application($this->tmpDir);
        $container = $app->container->get(Container::class);

        $this->assertSame($app->container, $container);
    }

    public function testContainerRegistersRouter(): void
    {
        $app    = new Application($this->tmpDir);
        $router = $app->container->get(Router::class);

        $this->assertSame($app->router, $router);
    }

    public function testContainerRegistersApplication(): void
    {
        $app      = new Application($this->tmpDir);
        $resolved = $app->container->get(Application::class);

        $this->assertSame($app, $resolved);
    }

    public function testBootLogInitializesLogger(): void
    {
        new Application($this->tmpDir);

        // Log::monolog() throws RuntimeException if init() was not called
        $this->assertInstanceOf(Logger::class, Log::monolog());
    }

    public function testContainerRegistersTraceContext(): void
    {
        $app = new Application($this->tmpDir);

        $a = $app->container->get(TraceContext::class);
        $b = $app->container->get(TraceContext::class);

        $this->assertInstanceOf(TraceContext::class, $a);
        $this->assertSame($a, $b);
    }

    public function testFrameworkProcessorsAreRegisteredOnBoot(): void
    {
        new Application($this->tmpDir);

        $processors = Log::monolog()->getProcessors();

        // The 4 framework processors: Trace / Span / ElapsedTime / ExtraContext
        $this->assertCount(4, $processors);
    }

    public function testFrameworkProcessorsInjectTraceInfoIntoLogRecords(): void
    {
        $app     = new Application($this->tmpDir);
        $context = $app->container->get(TraceContext::class);
        $this->assertInstanceOf(TraceContext::class, $context);

        $context->traceId = '0af7651916cd43dd8448eb211c80319c';
        $context->spanId  = 'b7ad6b7169203331';
        $context->set('user_id', 42);

        $handler = new TestHandler();
        Log::monolog()->setHandlers([$handler]);
        Log::monolog()->info('test');

        $record = $handler->getRecords()[0];
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $record->extra['trace_id']);
        $this->assertSame('b7ad6b7169203331', $record->extra['span_id']);
        $this->assertSame(42, $record->extra['user_id']);
        $this->assertArrayHasKey('elapsed_ms', $record->extra);
    }

    public function testFrameworkProcessorsApplyToConfigDefinedChannels(): void
    {
        $this->writeConfig('log.php', '<?php return [
            "default" => "app",
            "channels" => [
                "app" => ["driver" => "stream", "stream" => "php://memory"],
                "audit" => ["driver" => "stream", "stream" => "php://memory"],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);

        // Each config-defined channel must carry the 4 framework processors
        $this->assertCount(4, $manager->channel('app')->getProcessors());
        $this->assertCount(4, $manager->channel('audit')->getProcessors());
    }

    public function testConfigProcessorsAndFrameworkProcessorsCoexist(): void
    {
        $this->writeConfig('log.php', '<?php return [
            "channels" => [
                "app" => [
                    "driver" => "stream",
                    "stream" => "php://memory",
                    "processors" => ["memory_usage", "memory_peak"],
                ],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);

        // 4 framework processors + 2 config-declared Monolog processors
        $this->assertCount(6, $manager->channel('app')->getProcessors());
    }

    public function testEmptyTraceContextExtraDoesNotCorruptRecord(): void
    {
        new Application($this->tmpDir);

        $handler = new TestHandler();
        Log::monolog()->setHandlers([$handler]);
        Log::monolog()->info('test', ['ctx' => 'value']);

        $record = $handler->getRecords()[0];
        $this->assertSame(['ctx' => 'value'], $record->context);
    }

    public function testDispatchRouteThrowsForNonObjectController(): void
    {
        $this->writeRoutes('<?php
            $router->get("/test", "NonObjectController", "index");
        ');

        $app = new Application($this->tmpDir);
        $app->container->instance('NonObjectController', 'not an object');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Controller must be an object: NonObjectController');

        $app->run($this->createRequest('GET', '/test'));
    }

    public function testBuildMiddlewareDispatcherThrowsForInvalidMiddleware(): void
    {
        // HealthController does not implement MiddlewareInterface.
        file_put_contents(
            $this->tmpDir . '/config/middleware.php',
            '<?php return [\Sloop\Tests\Unit\Foundation\Stub\HealthController::class];',
        );

        $app = new Application($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Middleware must implement MiddlewareInterface: '
            . HealthController::class,
        );

        $app->run($this->createRequest('GET', '/anything'));
    }

    public function testHandleConvertsReflectionExceptionToRuntimeException(): void
    {
        // Route to a non-existent action method; RouteRequestHandler raises
        // \ReflectionException, which Application::handle wraps into a
        // \RuntimeException so callers do not handle Reflection internals.
        $this->writeRoutes('<?php
            $router->get("/test", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "nonExistentMethod");
        ');

        $app = new Application($this->tmpDir);

        try {
            $app->run($this->createRequest('GET', '/test'));
            $this->fail('Expected \RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(
                'Failed to reflect controller action: ',
                $e->getMessage(),
            );
            $this->assertInstanceOf(\ReflectionException::class, $e->getPrevious());
        }
    }

    public function testHandleConvertsReflectionExceptionThroughMiddlewareDispatcher(): void
    {
        // Even when the route is wrapped by a middleware dispatcher, a
        // \ReflectionException raised inside the final handler must still be
        // normalized to \RuntimeException at the Application::handle level.
        $this->writeRoutes('<?php
            $router->get("/test", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "nonExistentMethod")
                ->middleware(\Sloop\Tests\Unit\Foundation\Stub\XRequestIdMiddleware::class);
        ');

        $app = new Application($this->tmpDir);

        try {
            $app->run($this->createRequest('GET', '/test'));
            $this->fail('Expected \RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(
                'Failed to reflect controller action: ',
                $e->getMessage(),
            );
            $this->assertInstanceOf(\ReflectionException::class, $e->getPrevious());
        }
    }

    public function testConnectionManagerIsRegisteredAsSingleton(): void
    {
        $app = new Application($this->tmpDir);

        $first  = $app->container->get(ConnectionManager::class);
        $second = $app->container->get(ConnectionManager::class);

        $this->assertSame($first, $second);
    }

    public function testConnectionFactoryIsRegisteredAsSingleton(): void
    {
        $app = new Application($this->tmpDir);

        $first  = $app->container->get(ConnectionFactory::class);
        $second = $app->container->get(ConnectionFactory::class);

        $this->assertSame($first, $second);
    }

    public function testConnectionFactoryDefaultsToPdoConnectionFactory(): void
    {
        $app = new Application($this->tmpDir);

        $factory = $app->container->get(ConnectionFactory::class);

        $this->assertInstanceOf(PdoConnectionFactory::class, $factory);
    }

    public function testConnectionManagerUsesOverriddenConnectionFactoryBinding(): void
    {
        $this->writeConfig('database.php', '<?php return [
            "default" => "primary",
            "connections" => [
                "primary" => [
                    "driver"   => "mysql",
                    "host"     => "localhost",
                    "database" => "app",
                ],
            ],
        ];');

        $app = new Application($this->tmpDir);

        $customFactory = new class implements ConnectionFactory {
            public function make(ValidatedConfig $config, string $name): Connection
            {
                throw new \LogicException('custom factory was used');
            }
        };
        $app->container->instance(ConnectionFactory::class, $customFactory);

        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        try {
            $manager->connection();
            $this->fail('Expected LogicException from custom factory');
        } catch (\LogicException $e) {
            $this->assertSame('custom factory was used', $e->getMessage());
        }
    }

    public function testConnectionManagerThrowsWhenConnectionFactoryBindingIsInvalid(): void
    {
        $app = new Application($this->tmpDir);

        $app->container->instance(ConnectionFactory::class, new \stdClass());

        try {
            $app->container->get(ConnectionManager::class);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Container binding for ' . ConnectionFactory::class . ' must implement ConnectionFactory.',
                $e->getMessage(),
            );
        }
    }

    public function testConfigDatabaseDefaultIsInjected(): void
    {
        $this->writeConfig('database.php', '<?php return [
            "default" => "primary",
            "connections" => [
                "secondary" => [
                    "driver"   => "mysql",
                    "host"     => "localhost",
                    "database" => "app",
                ],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // If default = "primary" reached the ConnectionManager, the absence of
        // "primary" in connections must surface as "[primary] is not defined".
        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Database connection [primary] is not defined.',
                $e->getMessage(),
            );
        }
    }

    public function testConfigDatabaseConnectionsAreInjected(): void
    {
        $this->writeConfig('database.php', '<?php return [
            "default" => "primary",
            "connections" => [
                "primary" => [
                    "driver" => "mysql",
                    "host"   => "localhost",
                    // database is intentionally missing to surface the validation path
                ],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // If connections.primary reached the Resolver, its required-key check fires.
        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [primary]: missing required config key "database".',
                $e->getMessage(),
            );
        }
    }

    public function testConfigDatabaseConnectionsSkipsInvalidEntries(): void
    {
        // Invalid entries are in the middle to verify `continue` (not `break`):
        // audit must still be registered after the invalid entries are skipped.
        $this->writeConfig('database.php', '<?php return [
            "default" => "audit",
            "connections" => [
                "primary" => [
                    "driver"   => "mysql",
                    "host"     => "localhost",
                    "database" => "primary_db",
                ],
                0 => ["driver" => "mysql"],
                "invalid_scalar" => "not_an_array",
                "audit" => [
                    "driver" => "mysql",
                    "host"   => "localhost",
                    // database missing on purpose for assertion
                ],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // audit appears after the invalid entries; reaching it via the validation
        // error confirms iteration continues (instead of breaking on the first invalid).
        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [audit]: missing required config key "database".',
                $e->getMessage(),
            );
        }
    }

    public function testConfigDatabaseConnectionsFiltersNumericKeysInsideConnection(): void
    {
        $this->writeConfig('database.php', '<?php return [
            "default" => "primary",
            "connections" => [
                "primary" => [
                    "driver" => "mysql",
                    "host"   => "localhost",
                    0 => "ignored_numeric_key",
                    // database missing
                ],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // When int keys are stripped by filterStringKeys, the Resolver only
        // sees string keys and reports "missing required config key" instead
        // of "unsupported config key 0".
        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [primary]: missing required config key "database".',
                $e->getMessage(),
            );
        }
    }

    public function testConfigDatabaseDefaultFallsBackWhenNotString(): void
    {
        $this->writeConfig('database.php', '<?php return [
            "default" => 42,
            "connections" => [
                "primary" => [
                    "driver"   => "mysql",
                    "host"     => "localhost",
                    "database" => "app",
                ],
            ],
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // Non-string default falls back to "" — "[]" is not present in connections.
        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Database connection [] is not defined.',
                $e->getMessage(),
            );
        }
    }

    public function testConfigDatabaseConnectionsIgnoredWhenNotArray(): void
    {
        $this->writeConfig('database.php', '<?php return [
            "default" => "primary",
            "connections" => "not_an_array",
        ];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // Non-array connections fall back to []; "[primary]" is not present.
        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Database connection [primary] is not defined.',
                $e->getMessage(),
            );
        }
    }

    public function testConfigDatabaseFallsBackWhenConfigNotLoaded(): void
    {
        // Remove config directory before Application boot so Config::load() is skipped.
        rmdir($this->tmpDir . '/config');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // Config::isLoaded() is false → defaultName='' and configs=[].
        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Database connection [] is not defined.',
                $e->getMessage(),
            );
        }
    }

    public function testConfigDatabaseFallsBackWhenKeysAreMissing(): void
    {
        $this->writeConfig('database.php', '<?php return [];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(ConnectionManager::class);
        $this->assertInstanceOf(ConnectionManager::class, $manager);

        // database.default and database.connections are both undefined.
        // Config::get returns null, and is_string(null) / is_array(null) both
        // fall through to the same defaults as the explicit non-string/non-array cases.
        try {
            $manager->connection();
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Database connection [] is not defined.',
                $e->getMessage(),
            );
        }
    }
}
