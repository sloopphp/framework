<?php

declare(strict_types=1);

namespace Sloop\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Helpers for decoding and narrowing JSON response bodies in tests.
 *
 * PHPStan does not infer string keys from json_decode(), so tests need
 * explicit narrowing to access nested fields without offset errors.
 */
trait JsonBodyAssertions
{
    /**
     * Decode a JSON response body into a string-keyed array.
     *
     * On JSON decode failure, the test fails via `self::fail()` with
     * a descriptive message rather than propagating `JsonException`
     * through the test method signature.
     *
     * @param  ResponseInterface $response Response to decode
     * @return array<string, mixed>
     */
    private function decodeJsonBody(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail('Failed to decode JSON response body: ' . $e->getMessage());
        }

        return $this->narrowToStringArray($decoded);
    }

    /**
     * Narrow a mixed value to a string-keyed array.
     *
     * @param  mixed $value Value to narrow (typically a decoded JSON object)
     * @return array<string, mixed>
     */
    private function narrowToStringArray(mixed $value): array
    {
        self::assertIsArray($value);

        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }

        return $result;
    }
}
