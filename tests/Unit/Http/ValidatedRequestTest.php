<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http;

use DateTimeImmutable;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Sloop\Clock\FrozenClock;
use Sloop\Container\Container;
use Sloop\Http\Request\ValidatedRequest;
use Sloop\Http\Request\ValidatedRequestShape;
use Sloop\Http\Response\ApiResponseFormatter;
use Sloop\Http\RouteRequestHandler;
use Sloop\Routing\Route;
use Sloop\Tests\Unit\Http\Stub\DiService;
use Sloop\Tests\Unit\Http\Stub\Validated\AbstractRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\BaseInRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\ClockedRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\CreateUserRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\FileUploadRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\InjectedRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\MutablePropertyRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\NoRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\NotNullableRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\NullableRequiredRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\PrivatePropertyRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\PropertyWithoutRuleRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RequestInRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RulesListRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RulesNotArrayRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RulesNotFieldRuleRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RulesRecorder;
use Sloop\Tests\Unit\Http\Stub\Validated\RuleWithoutPropertyRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\StaticRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\UnionPropertyRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\ValidatedController;
use Sloop\Tests\Unit\Http\Stub\Validated\ValidatedInRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\WrongTypeRequest;

final class ValidatedRequestTest extends TestCase
{
    protected function setUp(): void
    {
        ValidatedController::reset();
        RulesRecorder::$received = [];
    }

    /**
     * @param array<string, string> $params
     *
     * @noinspection PhpDocMissingThrowsInspection, PhpUnhandledExceptionInspection
     */
    private function dispatch(
        string $action,
        ServerRequest $request,
        array $params = [],
        ?Container $container = null,
    ): ResponseInterface {
        $container ??= new Container();
        $container->instance(ValidatedController::class, new ValidatedController());

        $route   = new Route($request->getMethod(), '/dispatch', ValidatedController::class, $action);
        $handler = new RouteRequestHandler($container, $route, $params, new ApiResponseFormatter());

        return $handler->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function errors(ResponseInterface $response): array
    {
        $error = self::body($response)['error'] ?? null;
        self::assertIsArray($error);
        $errors = $error['errors'] ?? null;
        self::assertIsArray($errors);

        /** @var array<string, mixed> $errors */
        return $errors;
    }

    public function testFillsTypedPropertiesFromTheBodyOfAPost(): void
    {
        $request = new ServerRequest('POST', '/dispatch')->withParsedBody(['name' => 'Ann', 'age' => '42', 'extra' => 'x']);

        $response = $this->dispatch('create', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(CreateUserRequest::class, ValidatedController::$last);
        $this->assertSame('Ann', ValidatedController::$last->name);
        $this->assertSame(42, ValidatedController::$last->age);
        $this->assertSame(10, ValidatedController::$last->limit);
    }

    public function testLeavesAnOptionalFieldThatWasNotSentNull(): void
    {
        $this->dispatch('create', new ServerRequest('POST', '/dispatch')->withParsedBody(['name' => 'Ann']));

        $this->assertInstanceOf(CreateUserRequest::class, ValidatedController::$last);
        $this->assertNull(ValidatedController::$last->age);
    }

    public function testAnswers422WithTheErrorsWithoutCallingTheAction(): void
    {
        $request = new ServerRequest('POST', '/dispatch')->withParsedBody(['name' => 'Annabel', 'age' => 'x']);

        $response = $this->dispatch('create', $request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull(ValidatedController::$last);
        $this->assertSame(
            [
                'error' => [
                    'message' => 'Validation failed.',
                    'status'  => 422,
                    'errors'  => [
                        'name' => [['rule' => 'maxLength', 'params' => ['max' => 5, 'unit' => 'graphemes'], 'message' => 'The name field must not be longer than 5 characters.']],
                        'age'  => [['rule' => 'int', 'params' => [], 'message' => 'The age field must be an integer.']],
                    ],
                ],
            ],
            self::body($response),
        );
    }

    public function testDoesNotReadTheQueryStringOfAPost(): void
    {
        $request = new ServerRequest('POST', '/dispatch?name=Ann')->withQueryParams(['name' => 'Ann']);

        $response = $this->dispatch('create', $request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['name'], array_keys(self::errors($response)));
    }


    /**
     * @return array<string, array{string}>
     */
    public static function queryMethods(): array
    {
        return [
            'GET'    => ['GET'],
            'HEAD'   => ['HEAD'],
            'DELETE' => ['DELETE'],
        ];
    }

    #[DataProvider('queryMethods')]
    public function testValidatesTheQueryStringTogetherWithTheBody(string $method): void
    {
        $request = new ServerRequest($method, '/dispatch')
            ->withQueryParams(['age' => '42'])
            ->withParsedBody(['name' => 'Ann']);

        $response = $this->dispatch('create', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(CreateUserRequest::class, ValidatedController::$last);
        $this->assertSame('Ann', ValidatedController::$last->name);
        $this->assertSame(42, ValidatedController::$last->age);
    }

    #[DataProvider('queryMethods')]
    public function testAnswers400WhenAFieldIsSentInBothTheQueryStringAndTheBody(string $method): void
    {
        $request = new ServerRequest($method, '/dispatch')
            ->withQueryParams(['name' => 'Ann'])
            ->withParsedBody(['name' => 'Bob']);

        $response = $this->dispatch('create', $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull(ValidatedController::$last);
        $this->assertSame(
            ['error' => ['message' => 'The request value "name" was sent in both the query string and the body.', 'status' => 400]],
            self::body($response),
        );
    }

    public function testIgnoresAKeyWithoutARuleSentInBothPlaces(): void
    {
        $request = new ServerRequest('GET', '/dispatch')
            ->withQueryParams(['name' => 'Ann', 'extra' => '1'])
            ->withParsedBody(['extra' => '2']);

        $this->assertSame(200, $this->dispatch('create', $request)->getStatusCode());
    }

    public function testValidatesAnUploadedFile(): void
    {
        $file    = new UploadedFile('content', 7, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')->withUploadedFiles(['avatar' => $file]);

        $this->dispatch('upload', $request);

        $this->assertInstanceOf(FileUploadRequest::class, ValidatedController::$last);
        $this->assertSame($file, ValidatedController::$last->avatar);
    }

    public function testAnswers400WhenAFieldIsSentAsBothAFormValueAndAFile(): void
    {
        $file    = new UploadedFile('content', 7, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['avatar' => 'x'])
            ->withUploadedFiles(['avatar' => $file]);

        $response = $this->dispatch('upload', $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => 'The request value "avatar" was sent as both a form value and a file.', 'status' => 400]],
            self::body($response),
        );
    }

    public function testAcceptsANullablePropertyForARequiredField(): void
    {
        $this->dispatch('nullableRequired', new ServerRequest('POST', '/dispatch')->withParsedBody(['name' => 'Ann']));

        $this->assertInstanceOf(NullableRequiredRequest::class, ValidatedController::$last);
        $this->assertSame('Ann', ValidatedController::$last->name);
    }

    public function testResolvesTheArgumentsOfRulesLikeThoseOfTheAction(): void
    {
        $container = new Container();
        $container->instance(DiService::class, new DiService('svc'));

        $response = $this->dispatch(
            'update',
            new ServerRequest('PUT', '/dispatch')->withParsedBody(['name' => 'Ann']),
            ['id' => '7'],
            $container,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['svc', 7], RulesRecorder::$received);
        $this->assertInstanceOf(InjectedRulesRequest::class, ValidatedController::$last);
        $this->assertSame(7, ValidatedController::$lastId);
    }

    public function testValidatesWithTheClockRegisteredInTheContainer(): void
    {
        $container = new Container();
        $container->instance(ClockInterface::class, new FrozenClock(new DateTimeImmutable('2030-01-01T00:00:00+00:00')));
        $request = new ServerRequest('POST', '/dispatch')->withParsedBody(['at' => '2029-12-31T23:00:00+00:00']);

        $response = $this->dispatch('clocked', $request, [], $container);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testValidatesWithTheSystemClockWhenNoneIsRegistered(): void
    {
        $request = new ServerRequest('POST', '/dispatch')->withParsedBody(['at' => '2999-01-01T00:00:00+00:00']);

        $response = $this->dispatch('clocked', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(ClockedRequest::class, ValidatedController::$last);
    }

    public function testRefusesAnEntryRegisteredAsTheClockThatIsNotOne(): void
    {
        $container = new Container();
        $container->instance(ClockInterface::class, new DiService());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('The entry registered as Psr\\Clock\\ClockInterface is not a clock.');

        $this->dispatch('clocked', new ServerRequest('POST', '/dispatch'), [], $container);
    }

    public function testResolvesAParameterOfRulesWithAUnionTypeLikeTheAction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported parameter type for id');

        $this->dispatch('unionRulesParam', new ServerRequest('POST', '/dispatch'), ['id' => '1']);
    }

    public function testRefusesAParsedBodyThatIsNotAnArray(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('The parsed body of the request is not an array; a ValidatedRequest reads the body as one.');

        $this->dispatch('create', new ServerRequest('POST', '/dispatch')->withParsedBody(new \stdClass()));
    }

    public function testReadsTheShapeOfAClassOnce(): void
    {
        $this->assertSame(ValidatedRequestShape::of(CreateUserRequest::class), ValidatedRequestShape::of(CreateUserRequest::class));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function misdeclaredRequests(): array
    {
        return [
            'no rules()' => ['noRules', NoRulesRequest::class . ' has no rules() method.'],
            'static rules()' => ['staticRules', StaticRulesRequest::class . '::rules() must not be static.'],
            'property without private(set)' => ['mutableProperty', MutablePropertyRequest::class . '::$name must be declared public private(set): every property of a validated request receives a validated value.'],
            'private property' => ['privateProperty', PrivatePropertyRequest::class . '::$helper must be declared public private(set): every property of a validated request receives a validated value.'],
            'union type' => ['unionProperty', UnionPropertyRequest::class . '::$id needs a single type (with or without ?), the type of its field\'s validated value.'],
            'rules() not returning an array' => ['rulesNotArray', RulesNotArrayRequest::class . '::rules() must return an array of field rules.'],
            'rules() returning a list' => ['rulesList', RulesListRequest::class . '::rules() must return field rules keyed by field name.'],
            'rules() returning no FieldRule' => ['rulesNotFieldRule', RulesNotFieldRuleRequest::class . '::rules() must return field rules keyed by field name.'],
            'rule without property' => ['ruleWithoutProperty', RuleWithoutPropertyRequest::class . ' has a rule for "email" but no property to receive it.'],
            'property without rule' => ['propertyWithoutRule', PropertyWithoutRuleRequest::class . '::$email has no rule in rules().'],
            'type differs from the rule' => ['wrongType', WrongTypeRequest::class . '::$name is declared int, but its rule gives string.'],
            'optional field not nullable' => ['notNullable', NotNullableRequest::class . '::$age must accept null (?int): the field is neither required() nor given a default().'],
            'rules() takes the Request' => ['requestInRules', RequestInRulesRequest::class . '::rules() cannot receive Sloop\Http\Request\Request: a validated request reads only its validated input.'],
            'rules() takes a ValidatedRequest' => ['validatedInRules', ValidatedInRulesRequest::class . '::rules() cannot receive ' . CreateUserRequest::class . ': a validated request reads only its validated input.'],
            'rules() takes the base class' => ['baseInRules', BaseInRulesRequest::class . '::rules() cannot receive ' . ValidatedRequest::class . ': a validated request reads only its validated input.'],
            'the base class itself' => ['base', ValidatedRequest::class . ' cannot be instantiated, so it cannot be validated into.'],
            'abstract class' => ['abstract', AbstractRequest::class . ' cannot be instantiated, so it cannot be validated into.'],
        ];
    }

    #[DataProvider('misdeclaredRequests')]
    public function testRefusesAMisdeclaredRequestClass(string $action, string $message): void
    {
        try {
            $this->dispatch($action, new ServerRequest('POST', '/dispatch'));
        } catch (LogicException $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }
        $this->fail('LogicException was not thrown.');
    }
}
