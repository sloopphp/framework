<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Response;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sloop\Http\HttpStatus;
use Sloop\Http\Response\ApiResponseFormatter;
use Sloop\Tests\Support\JsonBodyAssertions;

final class ApiResponseFormatterTest extends TestCase
{
    use JsonBodyAssertions;

    private ApiResponseFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ApiResponseFormatter();
    }

    /**
     * @param  array<string, mixed> $meta Metadata
     */
    private function formatSuccess(mixed $data, array $meta = [], int $status = 200): ResponseInterface
    {
        try {
            return $this->formatter->success($data, $meta, $status);
        } catch (\JsonException $e) {
            self::fail('Formatter threw unexpected JsonException: ' . $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed> $errors Detailed errors
     */
    private function formatError(string $message, int $status = 400, array $errors = []): ResponseInterface
    {
        try {
            return $this->formatter->error($message, $status, $errors);
        } catch (\JsonException $e) {
            self::fail('Formatter threw unexpected JsonException: ' . $e->getMessage());
        }
    }

    public function testSuccessWrapsDataInDataKey(): void
    {
        $response = $this->formatSuccess(['name' => 'Alice']);
        $body     = $this->decodeJsonBody($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['name' => 'Alice'], $body['data']);
        $this->assertArrayNotHasKey('meta', $body);
    }

    public function testSuccessIncludesMetaWhenProvided(): void
    {
        $response = $this->formatSuccess(
            ['name' => 'Alice'],
            ['total' => 100, 'page' => 1],
        );
        $body     = $this->decodeJsonBody($response);

        $this->assertSame(['name' => 'Alice'], $body['data']);
        $this->assertSame(['total' => 100, 'page' => 1], $body['meta']);
    }

    public function testSuccessWithCustomStatus(): void
    {
        $response = $this->formatSuccess(['id' => 1], [], 201);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testSuccessContentTypeIsJson(): void
    {
        $response = $this->formatSuccess([]);

        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testSuccessWithNullData(): void
    {
        $response = $this->formatSuccess(null);
        $body     = $this->decodeJsonBody($response);

        $this->assertNull($body['data']);
    }

    public function testSuccessWithScalarData(): void
    {
        $response = $this->formatSuccess('hello');
        $body     = $this->decodeJsonBody($response);

        $this->assertSame('hello', $body['data']);
    }

    public function testErrorFormatsWithMessageAndStatus(): void
    {
        $response = $this->formatError('Not Found', 404);
        $body     = $this->decodeJsonBody($response);
        $error    = $this->narrowToStringArray($body['error']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $error['message']);
        $this->assertSame(404, $error['status']);
        $this->assertArrayNotHasKey('errors', $error);
    }

    public function testErrorIncludesDetailedErrors(): void
    {
        $response = $this->formatError('Validation failed', 422, [
            'email' => ['The email field is required.'],
            'name'  => ['The name must be at least 2 characters.'],
        ]);
        $body     = $this->decodeJsonBody($response);
        $error    = $this->narrowToStringArray($body['error']);
        $errors   = $this->narrowToStringArray($error['errors']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Validation failed', $error['message']);
        $this->assertSame(['The email field is required.'], $errors['email']);
        $this->assertSame(['The name must be at least 2 characters.'], $errors['name']);
    }

    public function testErrorDefaultsTo400(): void
    {
        $response = $this->formatError('Bad request');

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSuccessPreservesUnicodeAndSlashes(): void
    {
        $response = $this->formatSuccess(['name' => '日本語', 'url' => 'https://example.com/path']);
        $json     = (string) $response->getBody();

        $this->assertStringContainsString('日本語', $json);
        $this->assertStringContainsString('https://example.com/path', $json);
        $this->assertStringNotContainsString('\\u', $json);
        $this->assertStringNotContainsString('\\/', $json);
    }

    public function testCustomJsonOptionsAreApplied(): void
    {
        $formatter = new ApiResponseFormatter(JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        try {
            $response = $formatter->success(['key' => 'value']);
        } catch (\JsonException $e) {
            self::fail('Formatter threw unexpected JsonException: ' . $e->getMessage());
        }

        $this->assertStringContainsString("\n", (string) $response->getBody());
    }

    public function testDefaultJsonOptionsConstant(): void
    {
        $this->assertSame(
            ApiResponseFormatter::DEFAULT_JSON_OPTIONS,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    public function testGetJsonOptionsReturnsConfiguredOptions(): void
    {
        $this->assertSame(ApiResponseFormatter::DEFAULT_JSON_OPTIONS, $this->formatter->getJsonOptions());

        $custom    = JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR;
        $formatter = new ApiResponseFormatter($custom);

        $this->assertSame($custom, $formatter->getJsonOptions());
    }

    public function testErrorContentTypeIsJson(): void
    {
        $response = $this->formatError('Bad request');

        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testSuccessAcceptsHttpStatusConstant(): void
    {
        $response = $this->formatSuccess(['id' => 1], [], HttpStatus::Created);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testErrorAcceptsHttpStatusConstant(): void
    {
        $response = $this->formatError('Not Found', HttpStatus::NotFound);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testErrorWithEmptyMessage(): void
    {
        $response = $this->formatError('');
        $body     = $this->decodeJsonBody($response);
        $error    = $this->narrowToStringArray($body['error']);

        $this->assertSame('', $error['message']);
        $this->assertSame(400, $error['status']);
    }
}
