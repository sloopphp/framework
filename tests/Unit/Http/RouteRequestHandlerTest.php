<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http;

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Sloop\Container\Container;
use Sloop\Http\HttpStatus;
use Sloop\Http\Request\Request;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Http\RouteRequestHandler;
use Sloop\Routing\Route;
use Sloop\Tests\Unit\Http\Stub\DiController;
use Sloop\Tests\Unit\Http\Stub\DiService;
use Sloop\Tests\Unit\Http\Stub\ResponseDiController;

final class RouteRequestHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        DiController::reset();
    }

    /**
     * @param array<string, string> $params
     *
     * @noinspection PhpDocMissingThrowsInspection, PhpUnhandledExceptionInspection
     */
    private function dispatch(
        string $controller,
        string $action,
        array $params,
        ?Container $container = null,
    ): ResponseInterface {
        $container ??= new Container();
        $container->instance($controller, new $controller());

        $serverRequest = new ServerRequest('GET', '/dispatch');
        $sloopRequest  = new Request($serverRequest, $params);
        $route         = new Route('GET', '/dispatch', $controller, $action);

        $handler = new RouteRequestHandler(
            $container,
            $route,
            $sloopRequest,
            $params,
            $this->formatter(),
        );

        return $handler->handle($serverRequest);
    }

    private function formatter(): ResponseFormatterInterface
    {
        return new class () implements ResponseFormatterInterface {
            public function getJsonOptions(): int
            {
                return 0;
            }

            public function success(mixed $data, array $meta = [], int $status = HttpStatus::Ok): ResponseInterface
            {
                return new Psr7Response($status, [], 'success:' . json_encode($data, JSON_THROW_ON_ERROR));
            }

            public function error(string $message, int $status = HttpStatus::BadRequest, array $errors = []): ResponseInterface
            {
                return new Psr7Response($status, [], 'error:' . $message);
            }
        };
    }

    public function testInvokesLegacyUntypedSignatureWithPositionalArgs(): void
    {
        $this->dispatch(DiController::class, 'legacyUntyped', ['id' => '42']);

        $this->assertSame('42', DiController::$lastId);
        $this->assertInstanceOf(Request::class, DiController::$lastRequest);
    }

    public function testInjectsSloopRequestForTypedRequestParameter(): void
    {
        $this->dispatch(DiController::class, 'requestOnly', []);

        $this->assertInstanceOf(Request::class, DiController::$lastRequest);
    }

    public function testCastsIntRouteParameterByName(): void
    {
        $this->dispatch(DiController::class, 'requestAndInt', ['id' => '42']);

        $this->assertSame(42, DiController::$lastId);
    }

    public function testCastsFloatRouteParameter(): void
    {
        $this->dispatch(DiController::class, 'requestAndFloat', ['price' => '19.95']);

        $this->assertSame(19.95, DiController::$lastPrice);
    }

    public function testCastsBoolRouteParameterFromTruthyString(): void
    {
        $this->dispatch(DiController::class, 'requestAndBool', ['flag' => '1']);

        $this->assertTrue(DiController::$lastFlag);
    }

    public function testCastsBoolRouteParameterFromFalsyStringZero(): void
    {
        $this->dispatch(DiController::class, 'requestAndBool', ['flag' => '0']);

        $this->assertFalse(DiController::$lastFlag);
    }

    public function testCastsBoolRouteParameterFromLiteralFalse(): void
    {
        $this->dispatch(DiController::class, 'requestAndBool', ['flag' => 'false']);

        $this->assertFalse(DiController::$lastFlag);
    }

    public function testCastsBoolRouteParameterFromEmptyString(): void
    {
        $this->dispatch(DiController::class, 'requestAndBool', ['flag' => '']);

        $this->assertFalse(DiController::$lastFlag);
    }

    public function testCastsBoolRouteParameterFromUppercaseFalse(): void
    {
        $this->dispatch(DiController::class, 'requestAndBool', ['flag' => 'FALSE']);

        $this->assertFalse(DiController::$lastFlag);
    }

    public function testPassesStringRouteParameterUnchanged(): void
    {
        $this->dispatch(DiController::class, 'requestAndString', ['name' => 'alice']);

        $this->assertSame('alice', DiController::$lastName);
    }

    public function testResolvesObjectParameterFromContainer(): void
    {
        $container = new Container();
        $container->instance(DiService::class, new DiService('injected'));

        $this->dispatch(DiController::class, 'requestAndContainer', [], $container);

        $this->assertInstanceOf(DiService::class, DiController::$lastService);
        $this->assertSame('injected', DiController::$lastService->id);
    }

    public function testUsesDefaultValueWhenRouteParameterMissing(): void
    {
        $this->dispatch(DiController::class, 'builtinWithDefault', []);

        $this->assertSame(7, DiController::$lastPage);
    }

    public function testThrowsWhenBuiltinRouteParameterMissingAndNoDefault(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route parameter not found: id');

        $this->dispatch(DiController::class, 'requestAndInt', []);
    }

    public function testThrowsOnUnsupportedUnionType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only named types are supported in method DI');

        $this->dispatch(DiController::class, 'unionTyped', ['id' => '42']);
    }

    public function testReturnsControllerResponseUnchanged(): void
    {
        $response = $this->dispatch(ResponseDiController::class, 'returnsResponse', []);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('created', (string) $response->getBody());
    }

    public function testWrapsNonResponseResultThroughFormatter(): void
    {
        $response = $this->dispatch(ResponseDiController::class, 'returnsArray', []);

        $this->assertSame(HttpStatus::Ok, $response->getStatusCode());
        $this->assertSame('success:{"ok":true}', (string) $response->getBody());
    }

    public function testReusesCachedReflectionOnSecondInvocation(): void
    {
        $this->dispatch(DiController::class, 'requestAndInt', ['id' => '1']);
        $this->dispatch(DiController::class, 'requestAndInt', ['id' => '2']);

        $this->assertSame(2, DiController::$lastId);
    }
}
