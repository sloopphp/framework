<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation;

use Monolog\Handler\TestHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Sloop\Config\Config;
use Sloop\Foundation\Application;
use Sloop\Foundation\Path;
use Sloop\Http\Request\FormBody;
use Sloop\Http\Request\RawBody;
use Sloop\Log\Log;
use Sloop\Log\LogManager;
use Sloop\Security\Exception\SecurityConfigException;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\ValidationMessages;

final class ApplicationBodyLimitTest extends TestCase
{
    use ThrowsAssertions;

    private string $tmpDir;

    private function writeFile(string $path, string $content): void
    {
        file_put_contents($this->tmpDir . '/' . $path, $content);
    }

    private function app(): Application
    {
        $app     = new Application($this->tmpDir);
        $manager = $app->container->get(LogManager::class);
        $this->assertInstanceOf(LogManager::class, $manager);
        $manager->channel()->setHandlers([new TestHandler()]);

        return $app;
    }

    /**
     * @param int<0, max> $limit
     */
    private function tooLarge(int $limit, ?ServerRequestInterface $request = null): ServerRequestInterface
    {
        return RawBody::read(
            $request ?? new ServerRequest('POST', '/health', ['Content-Type' => 'application/json']),
            static fn (int $maxLength): string => substr(str_repeat('a', $limit + 1), 0, $maxLength),
            $limit,
        );
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sloop_app_body_limit_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');
        mkdir($this->tmpDir . '/routes');
        $this->writeFile('routes/api.php', '<?php
            $router->post("/health", \Sloop\Tests\Unit\Foundation\Stub\HealthController::class, "check");
        ');

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

    public function testATooLargeBodyIsA413NamingTheDefaultLimit(): void
    {
        $response = $this->app()->run($this->tooLarge(1_048_576));

        $this->assertSame(413, $response->getStatusCode());
        $this->assertStringContainsString('The request body is larger than 1048576 bytes.', (string) $response->getBody());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testTheMessageNamesTheConfiguredLimit(): void
    {
        $this->writeFile('config/security.php', "<?php return ['max_body_size' => 5];");

        $response = $this->app()->run($this->tooLarge(5));

        $this->assertSame(413, $response->getStatusCode());
        $this->assertStringContainsString('The request body is larger than 5 bytes.', (string) $response->getBody());
    }

    public function testATooLargeBodyIsA413EvenWithoutAMatchingRoute(): void
    {
        $response = $this->app()->handle($this->tooLarge(3, new ServerRequest('POST', '/nowhere')));

        $this->assertSame(413, $response->getStatusCode());
    }

    public function testTheSizeIsCheckedBeforeAFormPhpRefusedToRead(): void
    {
        $refused = FormBody::parse(
            new ServerRequest('PUT', '/health', ['Content-Type' => 'multipart/form-data']),
            static function (): never {
                throw new \RequestParseBodyException('POST Content-Length of 9000000 bytes exceeds the limit of 8388608 bytes');
            },
            1_048_576,
        );

        $response = $this->app()->run($this->tooLarge(3, $refused));

        $this->assertSame(413, $response->getStatusCode());
    }

    public function testABodyWithinTheLimitReachesTheRoute(): void
    {
        $request = RawBody::read(
            new ServerRequest('POST', '/health'),
            static fn (int $maxLength): string => substr('abc', 0, $maxLength),
            3,
        );

        $this->assertSame(200, $this->app()->run($request)->getStatusCode());
    }

    public function testAnUnreadableUriFromTheGlobalsIsA400WithTheSecurityHeaders(): void
    {
        $backup = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];
        try {
            // An authority with a port that is not a number cannot be parsed.
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '//x:abc'];
            $_GET    = [];
            $_POST   = [];
            $_COOKIE = [];
            $_FILES  = [];

            $response = $this->app()->run();
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = $backup;
        }

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('The request URI or headers could not be read.', (string) $response->getBody());
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedLimitProvider(): array
    {
        return [
            'zero'     => ['0'],
            'negative' => ['-1'],
            'a string' => ["'1M'"],
            'a float'  => ['1.5'],
            'a bool'   => ['true'],
        ];
    }

    #[DataProvider('malformedLimitProvider')]
    public function testBootFailsForAMalformedLimit(string $value): void
    {
        $this->writeFile('config/security.php', "<?php return ['max_body_size' => " . $value . '];');

        $e = $this->assertThrows(SecurityConfigException::class, fn () => new Application($this->tmpDir));

        $this->assertSame('security.max_body_size must be a positive integer number of bytes.', $e->getMessage());
    }

    public function testANullLimitTakesTheDefault(): void
    {
        $this->writeFile('config/security.php', "<?php return ['max_body_size' => null];");

        $response = $this->app()->run($this->tooLarge(1_048_576));

        $this->assertStringContainsString('larger than 1048576 bytes.', (string) $response->getBody());
    }

    public function testTheLimitOfOneByteIsAccepted(): void
    {
        $this->writeFile('config/security.php', "<?php return ['max_body_size' => 1];");

        $response = $this->app()->run($this->tooLarge(1));

        $this->assertStringContainsString('larger than 1 bytes.', (string) $response->getBody());
    }
}
