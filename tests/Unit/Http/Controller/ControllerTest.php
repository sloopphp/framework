<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Controller;

use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Controller\Controller;
use Sloop\Http\HttpStatus;
use Sloop\Http\Response\ApiResponseFormatter;
use Sloop\Http\Response\Response;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Tests\Support\JsonBodyAssertions;

final class ControllerTest extends TestCase
{
    use JsonBodyAssertions;

    private TestController $controller;

    protected function setUp(): void
    {
        $this->controller = new TestController(new ApiResponseFormatter());
    }

    public function testResponseBuildsJsonResponse(): void
    {
        $psr  = $this->controller->publicResponse(['name' => 'Alice'])->json();
        $body = $this->decodeJsonBody($psr);

        $this->assertSame(200, $psr->getStatusCode());
        $this->assertSame(['name' => 'Alice'], $body['data']);
    }

    public function testResponseWithNullDataPreservesNullInJsonOutput(): void
    {
        $psr  = $this->controller->publicResponse()->json();
        $body = $this->decodeJsonBody($psr);

        $this->assertNull($body['data']);
    }

    public function testNoContentReturns204(): void
    {
        $psr = $this->controller->publicNoContent();

        $this->assertSame(204, $psr->getStatusCode());
        $this->assertSame('', (string) $psr->getBody());
    }

    public function testRedirectReturns302(): void
    {
        $psr = $this->controller->publicRedirect('https://example.com');

        $this->assertSame(302, $psr->getStatusCode());
        $this->assertSame('https://example.com', $psr->getHeaderLine('Location'));
    }

    public function testRedirectWithCustomStatus(): void
    {
        $psr = $this->controller->publicRedirect('https://example.com', 301);

        $this->assertSame(301, $psr->getStatusCode());
    }

    public function testCreatedResponse(): void
    {
        $psr  = $this->controller->publicResponse(['id' => 42])->created();
        $body = $this->decodeJsonBody($psr);

        $this->assertSame(201, $psr->getStatusCode());
        $this->assertSame(['id' => 42], $body['data']);
    }

    public function testErrorResponse(): void
    {
        $psr   = $this->controller->publicResponse()->error('Not Found', 404);
        $body  = $this->decodeJsonBody($psr);
        $error = $this->narrowToStringArray($body['error']);

        $this->assertSame(404, $psr->getStatusCode());
        $this->assertSame('Not Found', $error['message']);
    }

    public function testSubclassWithExtraDependenciesPropagatesFormatter(): void
    {
        // Verifies the documented `parent::__construct($formatter)` pattern:
        // a subclass with its own constructor dependency must still receive
        // the formatter and have `response()` work correctly.
        $controller = new class (new ApiResponseFormatter(), 'Alice') extends Controller {
            public function __construct(
                ResponseFormatterInterface $formatter,
                private readonly string $name,
            ) {
                parent::__construct($formatter);
            }

            public function getProfile(): ResponseInterface
            {
                return $this->response(['name' => $this->name])->json();
            }
        };

        $psr  = $controller->getProfile();
        $body = $this->decodeJsonBody($psr);

        $this->assertSame(200, $psr->getStatusCode());
        $this->assertSame(['name' => 'Alice'], $body['data']);
    }

    public function testCustomFormatterIsUsedThroughResponseBuilder(): void
    {
        // Verifies the formatter abstraction is actually pluggable: any
        // ResponseFormatterInterface implementation can replace the default.
        // Uses a spy to capture arguments passed by the Response builder.
        $spyFormatter = new class () implements ResponseFormatterInterface {
            public mixed $lastData     = null;
            public int $lastStatus     = 0;
            public bool $successCalled = false;

            public function getJsonOptions(): int
            {
                return 0;
            }

            public function success(mixed $data, array $meta = [], int $status = 200): ResponseInterface
            {
                $this->successCalled = true;
                $this->lastData      = $data;
                $this->lastStatus    = $status;

                return new Psr7Response($status);
            }

            public function error(string $message, int $status = 400, array $errors = []): ResponseInterface
            {
                return new Psr7Response($status);
            }
        };

        $controller = new TestController($spyFormatter);
        $controller->publicResponse(['name' => 'Alice'])->json();

        $this->assertTrue($spyFormatter->successCalled);
        $this->assertSame(['name' => 'Alice'], $spyFormatter->lastData);
        $this->assertSame(200, $spyFormatter->lastStatus);
    }

    public function testResponseReturnsIndependentBuilders(): void
    {
        // Each response() call must return a fresh builder instance so that
        // mutations to one do not affect another.
        $builder1 = $this->controller->publicResponse(['name' => 'first']);
        $builder2 = $this->controller->publicResponse(['name' => 'second']);

        $builder1->status(404);

        $psr2  = $builder2->json();
        $body2 = $this->decodeJsonBody($psr2);

        $this->assertSame(200, $psr2->getStatusCode());
        $this->assertSame(['name' => 'second'], $body2['data']);
    }

    public function testRedirectAcceptsHttpStatusConstant(): void
    {
        $psr = $this->controller->publicRedirect('https://example.com', HttpStatus::PermanentRedirect);

        $this->assertSame(308, $psr->getStatusCode());
        $this->assertSame('https://example.com', $psr->getHeaderLine('Location'));
    }
}

/**
 * Concrete controller for testing protected methods.
 */
final class TestController extends Controller
{
    public function publicResponse(mixed $data = null): Response
    {
        return $this->response($data);
    }

    public function publicNoContent(): ResponseInterface
    {
        return $this->noContent();
    }

    public function publicRedirect(string $url, int $status = 302): ResponseInterface
    {
        return $this->redirect($url, $status);
    }
}
