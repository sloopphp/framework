<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Response;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sloop\Http\Response\ApiResponseFormatter;
use Sloop\Http\Response\Response;
use Sloop\Tests\Support\JsonBodyAssertions;

final class ResponseTest extends TestCase
{
    use JsonBodyAssertions;

    private ApiResponseFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ApiResponseFormatter();
    }

    private function buildRaw(Response $response, string $contentType = 'application/json; charset=utf-8'): ResponseInterface
    {
        try {
            return $response->raw($contentType);
        } catch (\JsonException $e) {
            self::fail('Response::raw() threw unexpected JsonException: ' . $e->getMessage());
        }
    }

    public function testJsonReturnsFormattedResponse(): void
    {
        $response = new Response(['name' => 'Alice'], $this->formatter);
        $psr      = $response->json();
        $body     = $this->decodeJsonBody($psr);

        $this->assertSame(200, $psr->getStatusCode());
        $this->assertSame(['name' => 'Alice'], $body['data']);
    }

    public function testJsonWithCustomStatus(): void
    {
        $response = new Response(['id' => 1], $this->formatter);
        $psr      = $response->status(201)->json();

        $this->assertSame(201, $psr->getStatusCode());
    }

    public function testJsonWithMeta(): void
    {
        $response = new Response(['items' => []], $this->formatter);
        $psr      = $response->meta(['total' => 50])->json();
        $body     = $this->decodeJsonBody($psr);

        $this->assertSame(['total' => 50], $body['meta']);
    }

    public function testErrorReturnsFormattedErrorResponse(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->error('Not Found', 404);
        $body     = $this->decodeJsonBody($psr);
        $error    = $this->narrowToStringArray($body['error']);

        $this->assertSame(404, $psr->getStatusCode());
        $this->assertSame('Not Found', $error['message']);
    }

    public function testErrorWithValidationErrors(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->error('Validation failed', 422, [
            'email' => ['Required'],
        ]);
        $body     = $this->decodeJsonBody($psr);
        $error    = $this->narrowToStringArray($body['error']);
        $errors   = $this->narrowToStringArray($error['errors']);

        $this->assertSame(['Required'], $errors['email']);
    }

    public function testRawBypassesFormatterWithArray(): void
    {
        $response = new Response(['custom' => 'structure'], $this->formatter);
        $psr      = $this->buildRaw($response);
        $body     = $this->decodeJsonBody($psr);

        $this->assertSame(['custom' => 'structure'], $body);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertSame('application/json; charset=utf-8', $psr->getHeaderLine('Content-Type'));
    }

    public function testRawWithStringDataUsesAsIs(): void
    {
        $csv      = "name,age\nAlice,30";
        $response = new Response($csv, $this->formatter);
        $psr      = $this->buildRaw($response, 'text/csv');

        $this->assertSame($csv, (string) $psr->getBody());
        $this->assertSame('text/csv', $psr->getHeaderLine('Content-Type'));
    }

    public function testRawWithCustomContentType(): void
    {
        $response = new Response('<root/>', $this->formatter);
        $psr      = $this->buildRaw($response, 'application/xml');

        $this->assertSame('<root/>', (string) $psr->getBody());
        $this->assertSame('application/xml', $psr->getHeaderLine('Content-Type'));
    }

    public function testRawWithCustomStatus(): void
    {
        $response = new Response(['ok' => true], $this->formatter);
        $psr      = $this->buildRaw($response->status(202));

        $this->assertSame(202, $psr->getStatusCode());
    }

    public function testCreatedReturns201(): void
    {
        $response = new Response(['id' => 42], $this->formatter);
        $psr      = $response->created();
        $body     = $this->decodeJsonBody($psr);

        $this->assertSame(201, $psr->getStatusCode());
        $this->assertSame(['id' => 42], $body['data']);
    }

    public function testNoContentReturns204(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->noContent();

        $this->assertSame(204, $psr->getStatusCode());
        $this->assertSame('', (string) $psr->getBody());
    }

    public function testRedirectReturns302WithLocation(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->redirect('https://example.com');

        $this->assertSame(302, $psr->getStatusCode());
        $this->assertSame('https://example.com', $psr->getHeaderLine('Location'));
    }

    public function testRedirectWithCustomStatus(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->redirect('https://example.com', 301);

        $this->assertSame(301, $psr->getStatusCode());
    }

    public function testHeaderAddsCustomHeader(): void
    {
        $response = new Response(['ok' => true], $this->formatter);
        $psr      = $response->header('X-Request-Id', 'abc-123')->json();

        $this->assertSame('abc-123', $psr->getHeaderLine('X-Request-Id'));
    }

    public function testHeadersAddsMultipleHeaders(): void
    {
        $response = new Response(['ok' => true], $this->formatter);
        $psr      = $response->headers([
            'X-Request-Id' => 'abc-123',
            'X-Trace-Id'   => 'trace-456',
        ])->json();

        $this->assertSame('abc-123', $psr->getHeaderLine('X-Request-Id'));
        $this->assertSame('trace-456', $psr->getHeaderLine('X-Trace-Id'));
    }

    public function testCustomHeadersApplyToError(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->header('X-Request-Id', 'err-789')->error('Bad', 400);

        $this->assertSame('err-789', $psr->getHeaderLine('X-Request-Id'));
    }

    public function testCustomHeadersApplyToRaw(): void
    {
        $response = new Response(['raw' => true], $this->formatter);
        $psr      = $this->buildRaw($response->header('X-Custom', 'value'));

        $this->assertSame('value', $psr->getHeaderLine('X-Custom'));
    }

    public function testCustomHeadersApplyToNoContent(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->header('X-Custom', 'value')->noContent();

        $this->assertSame('value', $psr->getHeaderLine('X-Custom'));
    }

    public function testCustomHeadersApplyToRedirect(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->header('X-Custom', 'value')->redirect('/home');

        $this->assertSame('value', $psr->getHeaderLine('X-Custom'));
    }

    public function testErrorDefaultsTo400(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $response->error('Bad request');

        $this->assertSame(400, $psr->getStatusCode());
    }

    public function testRawWithNullDataJsonEncodesNull(): void
    {
        $response = new Response(null, $this->formatter);
        $psr      = $this->buildRaw($response);

        $this->assertSame('null', (string) $psr->getBody());
        $this->assertSame('application/json; charset=utf-8', $psr->getHeaderLine('Content-Type'));
    }

    public function testHeadersOverwriteExistingHeader(): void
    {
        $response = new Response(['ok' => true], $this->formatter);
        $psr      = $response
            ->header('X-Request-Id', 'first')
            ->header('X-Request-Id', 'second')
            ->json();

        $this->assertSame('second', $psr->getHeaderLine('X-Request-Id'));
    }
}
