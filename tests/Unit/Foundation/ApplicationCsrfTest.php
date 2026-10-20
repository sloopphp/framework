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
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\ValidationMessages;

final class ApplicationCsrfTest extends TestCase
{
    use ThrowsAssertions;

    private string $tmpDir;

    private function writeFile(string $path, string $content): void
    {
        file_put_contents($this->tmpDir . '/' . $path, $content);
    }

    private function writeRoutes(): void
    {
        $this->writeFile('routes/api.php', '<?php
            $router->post("/open", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
            $router->post("/guarded", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check")
                ->middleware(\Sloop\Http\Middleware\CsrfMiddleware::class);
        ');
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $path, array $headers): ResponseInterface
    {
        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);
        $manager->channel()->setHandlers([new TestHandler()]);

        return $app->run(new ServerRequest('POST', $path, $headers));
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_csrf_test_' . bin2hex(random_bytes(8));
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

    public function testDoesNotCheckARouteWithoutTheMiddleware(): void
    {
        $this->writeRoutes();

        $response = $this->post('/open', ['Sec-Fetch-Site' => 'cross-site']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRejectsACrossSiteRequestOnARouteWithTheMiddleware(): void
    {
        $this->writeRoutes();

        $response = $this->post('/guarded', ['Sec-Fetch-Site' => 'cross-site']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('The request was rejected as a cross-site request.', (string) $response->getBody());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testRejectsACrossSiteRequestWithTheMiddlewareListedGlobally(): void
    {
        $this->writeRoutes();
        $this->writeFile('config/middleware.php', '<?php return [\Sloop\Http\Middleware\CsrfMiddleware::class];');

        $response = $this->post('/open', ['Sec-Fetch-Site' => 'cross-site']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testPassesASameOriginRequestWithTheMiddlewareListedGlobally(): void
    {
        $this->writeRoutes();
        $this->writeFile('config/middleware.php', '<?php return [\Sloop\Http\Middleware\CsrfMiddleware::class];');

        $response = $this->post('/open', ['Sec-Fetch-Site' => 'same-origin']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Sec-Fetch-Site', 'Origin'], $response->getHeader('Vary'));
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function configuredRequestProvider(): array
    {
        return [
            'own origin behind a proxy' => [['Origin' => 'https://example.org', 'Host' => 'backend.internal']],
            'trusted origin'            => [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://app.example']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('configuredRequestProvider')]
    public function testAppliesTheConfiguredOrigins(array $headers): void
    {
        $this->writeRoutes();
        $this->writeFile('config/security.php', "<?php return ['csrf' => [
            'origin'          => 'https://example.org',
            'trusted_origins' => ['https://app.example'],
        ]];");

        $this->assertSame(200, $this->post('/guarded', $headers)->getStatusCode());
    }

    public function testComparesWithHostWhenTheSectionOmitsTheOrigin(): void
    {
        $this->writeRoutes();
        $this->writeFile('config/security.php', "<?php return ['csrf' => ['origin' => null, 'trusted_origins' => null]];");

        $response = $this->post('/guarded', ['Origin' => 'https://backend.internal', 'Host' => 'backend.internal']);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedConfigProvider(): array
    {
        return [
            'csrf not an array'         => ["['csrf' => true]", 'security.csrf must be an array of settings.'],
            'unknown key'               => ["['csrf' => ['trusted' => []]]", 'security.csrf has an unsupported key "trusted".'],
            'origin not a string'       => ["['csrf' => ['origin' => 1]]", 'security.csrf.origin must be a string or null.'],
            'trusted not an array'      => ["['csrf' => ['trusted_origins' => 'https://app.example']]", 'security.csrf.trusted_origins must be a list of origins.'],
            'trusted with string keys'  => ["['csrf' => ['trusted_origins' => ['app' => 'https://app.example']]]", 'security.csrf.trusted_origins must be a list of origins.'],
            'trusted with a non-string' => ["['csrf' => ['trusted_origins' => ['https://app.example', null]]]", 'security.csrf.trusted_origins must be a list of origins.'],
            'malformed origin'          => ["['csrf' => ['origin' => 'https://example.org/']]", 'security.csrf: The origin "https://example.org/" must be written as scheme://host or scheme://host:port with http or https and no path.'],
            'malformed trusted origin'  => ["['csrf' => ['trusted_origins' => ['null']]]", 'security.csrf: The origin "null" must be written as scheme://host or scheme://host:port with http or https and no path.'],
        ];
    }

    #[DataProvider('malformedConfigProvider')]
    public function testBootFailsForAMalformedCsrfConfig(string $config, string $message): void
    {
        $this->writeFile('config/security.php', '<?php return ' . $config . ';');

        $e = $this->assertThrows(InvalidConfigException::class, fn () => new Application($this->tmpDir));

        $this->assertSame($message, $e->getMessage());
    }
}
