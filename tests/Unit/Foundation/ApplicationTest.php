<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Sloop\Config\Config;
use Sloop\Container\Container;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Routing\Router;
use Sloop\Tests\Support\JsonBodyAssertions;
use Sloop\Tests\Unit\Foundation\Stub\HealthController;

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

    public function testConfigLogChannelIsInjected(): void
    {
        $this->writeConfig('log.php', '<?php return ["channel" => "custom"];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);

        $this->assertInstanceOf(LogManager::class, $manager);
        $this->assertSame('custom', $manager->defaultChannel);
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
}
