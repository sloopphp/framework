<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http;

use LogicException;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Sloop\Container\Container;
use Sloop\Error\DomainException;
use Sloop\Http\HttpStatus;
use Sloop\Http\Request\Request;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Http\RouteRequestHandler;
use Sloop\Routing\Route;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Http\Stub\CacheKeyA;
use Sloop\Tests\Unit\Http\Stub\CacheKeyAb;
use Sloop\Tests\Unit\Http\Stub\CacheKeySameMethodA;
use Sloop\Tests\Unit\Http\Stub\CacheKeySameMethodB;
use Sloop\Tests\Unit\Http\Stub\DiController;
use Sloop\Tests\Unit\Http\Stub\DiService;
use Sloop\Tests\Unit\Http\Stub\ResponseDiController;

final class RouteRequestHandlerTest extends TestCase
{
    use ThrowsAssertions;

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
        $route         = new Route('GET', '/dispatch', $controller, $action);

        $handler = new RouteRequestHandler(
            $container,
            $route,
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

    public function testPassesLegacyArgumentsByPositionWhenRouteParameterNamesDiffer(): void
    {
        // Untyped legacy signatures are filled positionally, so a route
        // parameter whose name does not match the method's parameter must
        // still arrive; binding by name would fail here.
        $this->dispatch(DiController::class, 'legacyUntyped', ['userId' => '42']);

        $this->assertSame('42', DiController::$lastId);
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

    /**
     * @return array<string, array{string, string, int|float|bool}>
     */
    public static function readableValueProvider(): array
    {
        return [
            'int'                   => ['requestAndInt', '42', 42],
            'int with leading zero' => ['requestAndInt', '042', 42],
            'negative int'          => ['requestAndInt', '-5', -5],
            'largest int'           => ['requestAndInt', '9223372036854775807', PHP_INT_MAX],
            'float'                 => ['requestAndFloat', '19.95', 19.95],
            'float without a point' => ['requestAndFloat', '3', 3.0],
            'bool 1'                => ['requestAndBool', '1', true],
            'bool 0'                => ['requestAndBool', '0', false],
            'bool true'             => ['requestAndBool', 'true', true],
            'bool FALSE'            => ['requestAndBool', 'FALSE', false],
        ];
    }

    #[DataProvider('readableValueProvider')]
    public function testReadsARouteParameterTheWayTheRuleOfItsTypeDoes(string $method, string $value, int|float|bool $expected): void
    {
        $name = ['requestAndInt' => 'id', 'requestAndFloat' => 'price', 'requestAndBool' => 'flag'][$method];

        $this->dispatch(DiController::class, $method, [$name => $value]);

        $actual = ['requestAndInt' => DiController::$lastId, 'requestAndFloat' => DiController::$lastPrice, 'requestAndBool' => DiController::$lastFlag][$method];
        $this->assertSame($expected, $actual);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unreadableValueProvider(): array
    {
        return [
            'int with a fraction'  => ['requestAndInt', '1.5', 'The route parameter "id" is not a valid int.'],
            'int as an exponent'   => ['requestAndInt', '1e3', 'The route parameter "id" is not a valid int.'],
            'int with a plus sign' => ['requestAndInt', '+42', 'The route parameter "id" is not a valid int.'],
            'int out of range'     => ['requestAndInt', '9223372036854775808', 'The route parameter "id" is not a valid int.'],
            'float as an exponent' => ['requestAndFloat', '1e3', 'The route parameter "price" is not a valid float.'],
            'float without digits' => ['requestAndFloat', '.5', 'The route parameter "price" is not a valid float.'],
            'bool word no'         => ['requestAndBool', 'no', 'The route parameter "flag" is not a valid bool.'],
            'bool word on'         => ['requestAndBool', 'on', 'The route parameter "flag" is not a valid bool.'],
            'bool number 2'        => ['requestAndBool', '2', 'The route parameter "flag" is not a valid bool.'],
            'bool empty'           => ['requestAndBool', '', 'The route parameter "flag" is not a valid bool.'],
        ];
    }

    #[DataProvider('unreadableValueProvider')]
    public function testAnswersNotFoundForARouteParameterItsTypeCannotRead(string $method, string $value, string $message): void
    {
        $name = ['requestAndInt' => 'id', 'requestAndFloat' => 'price', 'requestAndBool' => 'flag'][$method];

        $e = $this->assertThrows(DomainException::class, fn () => $this->dispatch(DiController::class, $method, [$name => $value]));

        $this->assertSame(HttpStatus::NotFound, $e->statusCode);
        $this->assertSame($message, $e->getMessage());
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
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Route parameter not found: id');

        $this->dispatch(DiController::class, 'requestAndInt', []);
    }

    public function testReturnsNullForNullableBuiltinWhenRouteParameterAbsent(): void
    {
        $this->dispatch(DiController::class, 'nullableInt', []);

        $this->assertNull(DiController::$lastId);
    }

    public function testAnswersNotFoundForANonNumericIntParameter(): void
    {
        $e = $this->assertThrows(DomainException::class, fn () => $this->dispatch(DiController::class, 'requestAndInt', ['id' => 'abc']));

        $this->assertSame(HttpStatus::NotFound, $e->statusCode);
        $this->assertSame('The route parameter "id" is not a valid int.', $e->getMessage());
    }

    public function testAnswersNotFoundForANonNumericFloatParameter(): void
    {
        $e = $this->assertThrows(DomainException::class, fn () => $this->dispatch(DiController::class, 'requestAndFloat', ['price' => 'xyz']));

        $this->assertSame(HttpStatus::NotFound, $e->statusCode);
        $this->assertSame('The route parameter "price" is not a valid float.', $e->getMessage());
    }

    public function testThrowsOnUnsupportedUnionType(): void
    {
        $this->expectException(LogicException::class);
        // Naming the offending parameter is the point of the message, so assert
        // the whole sentence rather than the trailing explanation alone.
        $this->expectExceptionMessageIsOrContains('Unsupported parameter type for id: only named types are supported in method DI.');

        $this->dispatch(DiController::class, 'unionTyped', ['id' => '42']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function builtinTypesARouteValueCannotFillProvider(): array
    {
        return [
            'array'                => ['arrayTyped', 'array'],
            'array with a default' => ['arrayWithDefault', 'array'],
            'nullable array'       => ['nullableArray', '?array'],
            'callable'             => ['callableTyped', 'callable'],
        ];
    }

    #[DataProvider('builtinTypesARouteValueCannotFillProvider')]
    public function testRefusesARouteParameterForABuiltinTypeAStringCannotFill(string $action, string $type): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Route parameter "id" cannot be passed to ' . $type . ' $id: route values are read only into int, float, bool, string, mixed or untyped parameters.',
        );

        // 'strlen' would be a callable string: the client must not choose a function.
        $this->dispatch(DiController::class, $action, ['id' => 'strlen']);
    }

    public function testUsesTheDefaultOfAnArrayParameterTheRouteDoesNotName(): void
    {
        $this->dispatch(DiController::class, 'arrayWithDefault', []);

        $this->assertSame([], DiController::$lastId);
    }

    public function testPassesNullToANullableArrayParameterTheRouteDoesNotName(): void
    {
        DiController::$lastId = 'unset';

        $this->dispatch(DiController::class, 'nullableArray', []);

        $this->assertNull(DiController::$lastId);
    }

    public function testPassesTheRouteValueToAMixedParameterAsGiven(): void
    {
        $this->dispatch(DiController::class, 'mixedTyped', ['id' => '007']);

        $this->assertSame('007', DiController::$lastId);
    }

    public function testPassesTheRouteValueToAnUntypedParameterBesideTypedOnes(): void
    {
        $this->dispatch(DiController::class, 'untypedWithTyped', ['id' => '007']);

        $this->assertSame('007', DiController::$lastId);
    }

    public function testUsesTheDefaultOfAnUntypedParameterTheRouteDoesNotName(): void
    {
        $this->dispatch(DiController::class, 'untypedWithDefault', []);

        $this->assertSame('none', DiController::$lastId);
    }

    public function testThrowsForAnUntypedParameterTheRouteDoesNotNameWithoutADefault(): void
    {
        // An untyped parameter is required in PHP, so it is not filled with null.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Route parameter not found: id');

        $this->dispatch(DiController::class, 'untypedWithTyped', []);
    }

    public function testUsesTheDefaultOfAUnionParameterTheRouteDoesNotName(): void
    {
        $this->dispatch(DiController::class, 'unionWithDefault', []);

        $this->assertSame(1, DiController::$lastId);
    }

    public function testPassesNullToANullableUnionParameterTheRouteDoesNotName(): void
    {
        DiController::$lastId = 'unset';

        $this->dispatch(DiController::class, 'nullableUnion', []);

        $this->assertNull(DiController::$lastId);
    }

    public function testThrowsForAUnionParameterTheRouteDoesNotNameWithoutADefault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported parameter type for id: only named types are supported in method DI.');

        $this->dispatch(DiController::class, 'unionTyped', []);
    }

    public function testResolvesAClassParameterFromTheContainerEvenWhenTheRouteNamesIt(): void
    {
        $container = new Container();
        $container->instance(DiService::class, new DiService('injected'));

        $this->dispatch(DiController::class, 'serviceNamedLikeARouteParameter', ['id' => '7'], $container);

        $this->assertInstanceOf(DiService::class, DiController::$lastService);
        $this->assertSame('injected', DiController::$lastService->id);
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

    public function testParameterCacheDistinguishesSameMethodNameOnDifferentClasses(): void
    {
        $container = new Container();
        $container->instance(DiService::class, new DiService('injected'));

        $this->dispatch(CacheKeySameMethodA::class, 'show', ['id' => '42'], $container);
        $this->dispatch(CacheKeySameMethodB::class, 'show', ['id' => '42'], $container);

        $this->assertSame(42, CacheKeySameMethodA::$lastArg);
        $this->assertInstanceOf(DiService::class, CacheKeySameMethodB::$lastArg);
    }

    public function testParameterCacheDistinguishesClassAndMethodWithoutSeparator(): void
    {
        // CacheKeyA::bc and CacheKeyAb::c become the same string when concatenated without a separator.
        $container = new Container();
        $container->instance(DiService::class, new DiService('injected'));

        $this->dispatch(CacheKeyA::class, 'bc', ['id' => '42'], $container);
        $this->dispatch(CacheKeyAb::class, 'c', ['id' => '42'], $container);

        $this->assertSame(42, CacheKeyA::$lastArg);
        $this->assertInstanceOf(DiService::class, CacheKeyAb::$lastArg);
    }

}
