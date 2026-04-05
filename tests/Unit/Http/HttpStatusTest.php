<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Http\HttpStatus;

final class HttpStatusTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function statusCodeProvider(): array
    {
        return [
            // 1xx
            'Continue'           => ['Continue', 100],
            'SwitchingProtocols' => ['SwitchingProtocols', 101],
            'EarlyHints'         => ['EarlyHints', 103],

            // 2xx
            'Ok'        => ['Ok', 200],
            'Created'   => ['Created', 201],
            'Accepted'  => ['Accepted', 202],
            'NoContent' => ['NoContent', 204],

            // 3xx
            'MovedPermanently'  => ['MovedPermanently', 301],
            'Found'             => ['Found', 302],
            'NotModified'       => ['NotModified', 304],
            'TemporaryRedirect' => ['TemporaryRedirect', 307],
            'PermanentRedirect' => ['PermanentRedirect', 308],

            // 4xx
            'BadRequest'          => ['BadRequest', 400],
            'Unauthorized'        => ['Unauthorized', 401],
            'Forbidden'           => ['Forbidden', 403],
            'NotFound'            => ['NotFound', 404],
            'MethodNotAllowed'    => ['MethodNotAllowed', 405],
            'Conflict'            => ['Conflict', 409],
            'Gone'                => ['Gone', 410],
            'UnprocessableEntity' => ['UnprocessableEntity', 422],
            'TooManyRequests'     => ['TooManyRequests', 429],

            // 5xx
            'InternalServerError' => ['InternalServerError', 500],
            'NotImplemented'      => ['NotImplemented', 501],
            'BadGateway'          => ['BadGateway', 502],
            'ServiceUnavailable'  => ['ServiceUnavailable', 503],
            'GatewayTimeout'      => ['GatewayTimeout', 504],
        ];
    }

    #[DataProvider('statusCodeProvider')]
    public function testConstantHasCorrectValue(string $name, int $expected): void
    {
        $this->assertSame($expected, \constant(HttpStatus::class . '::' . $name));
    }

    public function testAllConstantsAreIntegers(): void
    {
        $reflection = new \ReflectionClass(HttpStatus::class);

        foreach ($reflection->getConstants() as $name => $value) {
            $this->assertIsInt($value, 'Constant ' . $name . ' should be int');
        }
    }

    public function testNoConstantValueIsDuplicated(): void
    {
        $reflection = new \ReflectionClass(HttpStatus::class);
        $constants  = $reflection->getConstants();
        $values     = [];

        foreach ($constants as $name => $value) {
            $this->assertNotContains($value, $values, 'Duplicate value for constant ' . $name);
            $values[] = $value;
        }
    }

    public function testAllConstantsAreInValidHttpRange(): void
    {
        $reflection = new \ReflectionClass(HttpStatus::class);

        foreach ($reflection->getConstants() as $name => $value) {
            $this->assertGreaterThanOrEqual(100, $value, $name . ' should be >= 100');
            $this->assertLessThanOrEqual(599, $value, $name . ' should be <= 599');
        }
    }
}
