<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use Monolog\Handler\TestHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sloop\Config\Config;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Http\Middleware\CorsMiddleware;
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Security\Exception\SecurityConfigException;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\ValidationMessages;

final class ApplicationCorsTest extends TestCase
{
    use ThrowsAssertions;

    private string $tmpDir;

    private function writeFile(string $path, string $content): void
    {
        file_put_contents($this->tmpDir . '/' . $path, $content);
    }

    private function boot(): Application
    {
        $this->writeFile('routes/api.php', '<?php
            $router->get("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');
        $this->writeFile('config/middleware.php', '<?php return [\Sloop\Http\Middleware\CorsMiddleware::class];');

        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);
        $manager->channel()->setHandlers([new TestHandler()]);

        return $app;
    }

    private function preflight(Application $app, string $origin): ResponseInterface
    {
        return $app->run(new ServerRequest('OPTIONS', '/health', ['Origin' => $origin]));
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_cors_test_' . bin2hex(random_bytes(8));
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

    public function testAppliesTheConfiguredSection(): void
    {
        $this->writeFile('config/security.php', "<?php return ['cors' => [
            'allowed_origins'   => ['https://app.example'],
            'allowed_methods'   => ['GET', 'PATCH'],
            'allowed_headers'   => ['X-Token'],
            'max_age'           => 600,
            'allow_credentials' => true,
        ]];");

        $response = $this->preflight($this->boot(), 'https://app.example');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, PATCH', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('X-Token', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testKeepsTheDefaultsForMissingAndNullEntries(): void
    {
        $this->writeFile('config/security.php', "<?php return ['cors' => [
            'allowed_origins'   => ['https://app.example'],
            'allowed_methods'   => null,
            'max_age'           => null,
            'allow_credentials' => null,
        ]];");

        $response = $this->preflight($this->boot(), 'https://app.example');

        $this->assertSame('GET, POST, PUT, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testAllowsNoOriginWithoutTheSection(): void
    {
        $response = $this->preflight($this->boot(), 'https://app.example');

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testABindingMadeAfterTheBootReplacesTheConfiguredMiddleware(): void
    {
        $this->writeFile('config/security.php', "<?php return ['cors' => ['allowed_origins' => ['https://app.example']]];");
        $app = $this->boot();
        $app->container->bind(CorsMiddleware::class, static fn (): CorsMiddleware => new CorsMiddleware(allowedOrigins: ['https://other.example']));

        $this->assertFalse($this->preflight($app, 'https://app.example')->hasHeader('Access-Control-Allow-Origin'));
        $this->assertSame('https://other.example', $this->preflight($app, 'https://other.example')->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedConfigProvider(): array
    {
        return [
            'cors not an array'         => ["['cors' => true]", 'security.cors must be an array of settings.'],
            'unknown key'               => ["['cors' => ['allowed_origin' => ['https://app.example']]]", 'security.cors has an unsupported key "allowed_origin".'],
            'origins not an array'      => ["['cors' => ['allowed_origins' => 'https://app.example']]", 'security.cors.allowed_origins must be a list of strings.'],
            'origins with string keys'  => ["['cors' => ['allowed_origins' => ['app' => 'https://app.example']]]", 'security.cors.allowed_origins must be a list of strings.'],
            'methods with a non-string' => ["['cors' => ['allowed_methods' => ['GET', 1]]]", 'security.cors.allowed_methods must be a list of strings.'],
            'headers not an array'      => ["['cors' => ['allowed_headers' => 'X-Token']]", 'security.cors.allowed_headers must be a list of strings.'],
            'max age not an integer'    => ["['cors' => ['max_age' => '600']]", 'security.cors.max_age must be an integer number of seconds.'],
            'credentials not a boolean' => ["['cors' => ['allow_credentials' => 'true']]", 'security.cors.allow_credentials must be true or false.'],
            'origin with a path'        => ["['cors' => ['allowed_origins' => ['https://app.example/']]]", 'security.cors: The origin "https://app.example/" must be written as scheme://host or scheme://host:port and no path.'],
            'negative max age'          => ["['cors' => ['max_age' => -1]]", 'security.cors: The max age must be 0 or more seconds; -1 was given.'],
            'credentialed * method'     => ["['cors' => ['allowed_methods' => ['*'], 'allow_credentials' => true]]", 'security.cors: The method "*" must not be allowed together with credentials; list the methods instead.'],
            'wildcard with credentials' => ["['cors' => ['allowed_origins' => ['*'], 'allow_credentials' => true]]", 'security.cors: The origin "*" must not be allowed together with credentials.'],
        ];
    }

    #[DataProvider('malformedConfigProvider')]
    public function testBootFailsForAMalformedCorsConfig(string $config, string $message): void
    {
        $this->writeFile('config/security.php', '<?php return ' . $config . ';');

        $e = $this->assertThrows(SecurityConfigException::class, fn () => new Application($this->tmpDir));

        $this->assertSame($message, $e->getMessage());
    }
}
