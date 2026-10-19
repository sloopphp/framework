<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use Monolog\Handler\TestHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sloop\Config\Config;
use Sloop\Encryption\Exception\InvalidConfigException;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Http\Middleware\CorsMiddleware;
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Validation\ValidationMessages;

final class ApplicationSecurityHeadersTest extends TestCase
{
    private string $tmpDir;

    private function writeFile(string $path, string $content): void
    {
        file_put_contents($this->tmpDir . '/' . $path, $content);
    }

    private function writeRoutes(): void
    {
        $this->writeFile('routes/api.php', '<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
            $router->get("/boom", \Sloop\Tests\Unit\Foundation\Stub\ThrowingController::class, "generic");
        ');
    }

    private function get(string $path): ResponseInterface
    {
        return new Application($this->tmpDir)->run(new ServerRequest('GET', $path));
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_security_headers_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');
        mkdir($this->tmpDir . '/routes');

        Path::reset();
        Config::reset();
        Log::reset();
        ValidationMessages::reset();
    }

    protected function tearDown(): void
    {
        Path::reset();
        Config::reset();
        Log::reset();
        ValidationMessages::reset();

        foreach (['config/security.php', 'config/middleware.php', 'routes/api.php'] as $file) {
            if (is_file($this->tmpDir . '/' . $file)) {
                unlink($this->tmpDir . '/' . $file);
            }
        }
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir . '/routes');
        rmdir($this->tmpDir);
    }

    public function testSendsTheDefaultHeadersWithoutAConfigFile(): void
    {
        $this->writeRoutes();

        $response = $this->get('/health');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testSendsTheHeadersOnANotFoundResponse(): void
    {
        $this->writeRoutes();

        $response = $this->get('/missing');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testSendsTheHeadersOnAnErrorResponseFromAController(): void
    {
        $this->writeRoutes();

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);
        $manager->channel()->setHandlers([new TestHandler()]);

        $response = $app->run(new ServerRequest('GET', '/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testSendsTheHeadersOnAResponseAGlobalMiddlewareReturnsEarly(): void
    {
        $this->writeRoutes();
        $this->writeFile(
            'config/middleware.php',
            '<?php return [\Sloop\Tests\Unit\Foundation\Stub\ShortCircuitMiddleware::class];',
        );

        $response = $this->get('/health');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testSendsTheHeadersOnAnErrorResponseForAnExceptionFromAGlobalMiddleware(): void
    {
        $this->writeRoutes();
        $this->writeFile(
            'config/middleware.php',
            '<?php return [\Sloop\Tests\Unit\Foundation\Stub\ThrowingMiddleware::class];',
        );

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);
        $manager->channel()->setHandlers([new TestHandler()]);

        $response = $app->run(new ServerRequest('GET', '/health'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testAddsTheHeadersToACorsPreflight(): void
    {
        $this->writeRoutes();
        $this->writeFile('config/middleware.php', '<?php return [\\Sloop\\Http\\Middleware\\CorsMiddleware::class];');

        $app = new Application($this->tmpDir);
        $app->container->bind(CorsMiddleware::class, static fn (): CorsMiddleware => new CorsMiddleware(['allowed_origins' => ['https://app.example']]));

        $response = $app->run(new ServerRequest('OPTIONS', '/health', ['Origin' => 'https://app.example']));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testKeepsAHeaderARouteMiddlewareSet(): void
    {
        $this->writeFile('routes/api.php', '<?php
            $router->get("/health", \\Sloop\\Tests\\Unit\\Foundation\\Stub\\HealthController::class, "check")
                ->middleware(\\Sloop\\Tests\\Unit\\Foundation\\Stub\\SameOriginFrameMiddleware::class);
        ');

        $response = $this->get('/health');

        $this->assertSame(['SAMEORIGIN'], $response->getHeader('X-Frame-Options'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function alsoListedMiddlewareProvider(): array
    {
        return [
            'in config/middleware.php' => ['<?php return [\\Sloop\\Http\\Middleware\\SecurityHeadersMiddleware::class];', '/health'],
            'on a route'               => ['<?php return [];', '/routed'],
        ];
    }

    #[DataProvider('alsoListedMiddlewareProvider')]
    public function testTheConfiguredHeadersApplyWhenTheMiddlewareIsAlsoListed(string $middlewareConfig, string $path): void
    {
        $this->writeFile('routes/api.php', '<?php
            $router->get("/health", \\Sloop\\Tests\\Unit\\Foundation\\Stub\\HealthController::class, "check");
            $router->get("/routed", \\Sloop\\Tests\\Unit\\Foundation\\Stub\\HealthController::class, "check")
                ->middleware(\\Sloop\\Http\\Middleware\\SecurityHeadersMiddleware::class);
        ');
        $this->writeFile('config/middleware.php', $middlewareConfig);
        $this->writeFile('config/security.php', "<?php return ['headers' => ['X-Frame-Options' => null, 'Referrer-Policy' => 'same-origin']];");

        $response = $this->get($path);

        $this->assertFalse($response->hasHeader('X-Frame-Options'));
        $this->assertSame(['same-origin'], $response->getHeader('Referrer-Policy'));
    }

    public function testAppliesTheConfiguredHeaders(): void
    {
        $this->writeRoutes();
        $this->writeFile('config/security.php', "<?php return ['headers' => [
            'X-Frame-Options'           => 'SAMEORIGIN',
            'Referrer-Policy'           => null,
            'Strict-Transport-Security' => 'max-age=31536000',
        ]];");

        $response = $this->get('/health');

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertFalse($response->hasHeader('Referrer-Policy'));
        $this->assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testSendsTheDefaultHeadersWhenTheSectionOmitsHeaders(): void
    {
        $this->writeRoutes();
        $this->writeFile('config/security.php', '<?php return [];');

        $this->assertSame('DENY', $this->get('/health')->getHeaderLine('X-Frame-Options'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedConfigProvider(): array
    {
        return [
            'unknown key'           => ["['header' => []]", 'security has an unsupported key "header".'],
            'headers not an array'  => ["['headers' => 'nosniff']", 'security.headers must be an array of header names mapped to a value or null.'],
            'invalid header value'  => ["['headers' => ['X-Frame-Options' => false]]", 'security.headers: The value of the header "X-Frame-Options" must be a non-blank string or null.'],
        ];
    }

    #[DataProvider('malformedConfigProvider')]
    public function testBootFailsForAMalformedSecurityConfig(string $config, string $message): void
    {
        $this->writeFile('config/security.php', '<?php return ' . $config . ';');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new Application($this->tmpDir);
    }
}
