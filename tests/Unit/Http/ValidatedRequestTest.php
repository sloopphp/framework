<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http;

use DateTimeImmutable;
use InvalidArgumentException;
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
use Sloop\Error\DomainException;
use Sloop\Http\HttpStatus;
use Sloop\Http\Request\ValidatedRequest;
use Sloop\Http\Request\ValidatedRequestShape;
use Sloop\Http\Response\ApiResponseFormatter;
use Sloop\Http\RouteRequestHandler;
use Sloop\Routing\Route;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Tests\Unit\Http\Stub\DiService;
use Sloop\Tests\Unit\Http\Stub\Validated\AbstractRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\BaseInRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\BoundedListRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\ClockedRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\CreateUserRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\DefaultNullableRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\FileUploadRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\InjectedRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\InnerUnboundedListRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\MatrixRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\MutablePropertyRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\NestedFormRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\NoRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\NotNullableRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\NullableRequiredRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\PhotoListRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\PrivateHelperBase;
use Sloop\Tests\Unit\Http\Stub\Validated\PrivatePropertyRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\PropertyDefaultRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\PropertyWithoutRuleRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RenamingRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RequestInRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RulesListRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RulesNotArrayRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RulesNotFieldRuleRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\RulesRecorder;
use Sloop\Tests\Unit\Http\Stub\Validated\RuleWithoutPropertyRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\StaticRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\TrimmedRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\UnboundedListRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\UnionPropertyRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\ValidatedController;
use Sloop\Tests\Unit\Http\Stub\Validated\ValidatedInRulesRequest;
use Sloop\Tests\Unit\Http\Stub\Validated\WrongTypeRequest;

final class ValidatedRequestTest extends TestCase
{
    use ThrowsAssertions;

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

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function emptyOnOneSide(): array
    {
        return [
            'null in the body'          => [['name' => 'Ann'], ['name' => null], 'Ann'],
            'empty string in the body'  => [['name' => 'Ann'], ['name' => ''], 'Ann'],
            'empty string in the query' => [['name' => ''], ['name' => 'Bob'], 'Bob'],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    #[DataProvider('emptyOnOneSide')]
    public function testTakesTheValueWhenTheOtherPlaceSendsItEmpty(array $query, array $body, string $expected): void
    {
        $request = new ServerRequest('GET', '/dispatch')->withQueryParams($query)->withParsedBody($body);

        $response = $this->dispatch('create', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(CreateUserRequest::class, ValidatedController::$last);
        $this->assertSame($expected, ValidatedController::$last->name);
    }

    public function testTreatsAFieldSentEmptyInBothPlacesAsEmpty(): void
    {
        $request = new ServerRequest('GET', '/dispatch')
            ->withQueryParams(['name' => 'Ann', 'age' => ''])
            ->withParsedBody(['age' => null]);

        $response = $this->dispatch('create', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(CreateUserRequest::class, ValidatedController::$last);
        $this->assertNull(ValidatedController::$last->age);
    }

    public function testAnswers400WhenAFileAndTheQueryStringSendTheSameFieldOfADelete(): void
    {
        $file    = new UploadedFile('content', 7, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('DELETE', '/dispatch')
            ->withQueryParams(['avatar' => 'x'])
            ->withUploadedFiles(['avatar' => $file]);

        $response = $this->dispatch('upload', $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => 'The request value "avatar" was sent in both the query string and the body.', 'status' => 400]],
            self::body($response),
        );
    }

    public function testTakesTheFileWhenTheFormValueIsEmpty(): void
    {
        $file    = new UploadedFile('content', 7, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['avatar' => ''])
            ->withUploadedFiles(['avatar' => $file]);

        $response = $this->dispatch('upload', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(FileUploadRequest::class, ValidatedController::$last);
        $this->assertSame($file, ValidatedController::$last->avatar);
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

    public function testAnswersNotFoundWhenARouteParameterOfRulesIsNotANumber(): void
    {
        $container = new Container();
        $container->instance(DiService::class, new DiService('svc'));

        $e = $this->assertThrows(DomainException::class, fn () => $this->dispatch(
            'update',
            new ServerRequest('PUT', '/dispatch')->withParsedBody(['name' => 'Ann']),
            ['id' => 'abc'],
            $container,
        ));

        $this->assertSame(HttpStatus::NotFound, $e->statusCode);
        $this->assertSame('The route parameter "id" is not a valid int.', $e->getMessage());
        $this->assertNull(ValidatedController::$last);
    }

    public function testAnswersNotFoundWhenABoolRouteParameterOfRulesIsNotABool(): void
    {
        $e = $this->assertThrows(DomainException::class, fn () => $this->dispatch(
            'flagged',
            new ServerRequest('PUT', '/dispatch')->withParsedBody(['name' => 'Ann']),
            ['flag' => 'yes'],
        ));

        $this->assertSame(HttpStatus::NotFound, $e->statusCode);
        $this->assertSame('The route parameter "flag" is not a valid bool.', $e->getMessage());
        $this->assertNull(ValidatedController::$last);
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

    #[DataProvider('queryMethods')]
    public function testAnswers422WhateverTheMethod(string $method): void
    {
        $response = $this->dispatch('create', new ServerRequest($method, '/dispatch'));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['name'], array_keys(self::errors($response)));
    }

    public function testEncodesEmptyParamsAsAJsonObject(): void
    {
        $request = new ServerRequest('POST', '/dispatch')->withParsedBody(['name' => 'Ann', 'age' => 'x']);

        $body = (string) $this->dispatch('create', $request)->getBody();

        $this->assertStringContainsString('"age":[{"rule":"int","params":{},', $body);
    }

    public function testAcceptsANullablePropertyForAFieldWithADefault(): void
    {
        $this->dispatch('defaultNullable', new ServerRequest('POST', '/dispatch'));

        $this->assertInstanceOf(DefaultNullableRequest::class, ValidatedController::$last);
        $this->assertSame(10, ValidatedController::$last->limit);
    }

    public function testAcceptsListsBoundedByExactOrBetweenCount(): void
    {
        $request = new ServerRequest('POST', '/dispatch')->withParsedBody(['items' => [['1'], ['2', '3']]]);

        $this->assertSame(200, $this->dispatch('boundedList', $request)->getStatusCode());
        $this->assertInstanceOf(BoundedListRequest::class, ValidatedController::$last);
        $this->assertSame([[1], [2, 3]], ValidatedController::$last->items);
    }

    public function testMergesTheFormAndTheFilesOfOneNestedField(): void
    {
        $file    = new UploadedFile('content', 7, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['user' => ['name' => 'Ann']])
            ->withUploadedFiles(['user' => ['avatar' => $file]]);

        $response = $this->dispatch('nestedForm', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(NestedFormRequest::class, ValidatedController::$last);
        $this->assertSame(['name' => 'Ann', 'avatar' => $file], ValidatedController::$last->user);
    }

    public function testMergesANestedFieldWhoseFileHalfIsEmpty(): void
    {
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['user' => ['name' => 'Ann']])
            ->withUploadedFiles(['user' => []]);

        $this->assertSame(200, $this->dispatch('nestedForm', $request)->getStatusCode());
    }

    public function testAnswers400WhenANestedKeyIsSentAsBothAFormValueAndAFile(): void
    {
        $file    = new UploadedFile('content', 7, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['user' => ['name' => 'Ann', 'avatar' => 'x']])
            ->withUploadedFiles(['user' => ['avatar' => $file]]);

        $response = $this->dispatch('nestedForm', $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => 'The request value "user" was sent as both a form value and a file.', 'status' => 400]],
            self::body($response),
        );
    }

    public function testAnswers400WhenAFormValueAndANestedFileShareAField(): void
    {
        $file    = new UploadedFile('content', 7, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['user' => 'Ann'])
            ->withUploadedFiles(['user' => ['avatar' => $file]]);

        $this->assertSame(400, $this->dispatch('nestedForm', $request)->getStatusCode());
    }

    public function testValidatesAFormValueWhoseFileHalfHoldsNoFile(): void
    {
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['user' => 'Ann'])
            ->withUploadedFiles(['user' => []]);

        $response = $this->dispatch('nestedForm', $request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['user'], array_keys(self::errors($response)));
    }

    public function testRefusesAMisdeclaredRuleBeforeReadingTheInput(): void
    {
        $request = new ServerRequest('GET', '/dispatch')
            ->withQueryParams(['name' => 'a'])
            ->withParsedBody(['name' => 'a']);

        try {
            $this->dispatch('misreferenced', $request);
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Field "name" compares with "missing", which has no rule.', $e->getMessage());

            return;
        }
        $this->fail('InvalidArgumentException was not thrown.');
    }

    public function testPutsAListElementFoundOnlyInTheFilesAfterTheOnesInTheBody(): void
    {
        $zero    = new UploadedFile('zero', 4, UPLOAD_ERR_OK, 'zero.txt', 'text/plain');
        $one     = new UploadedFile('one', 3, UPLOAD_ERR_OK, 'one.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['items' => [1 => ['public' => '1']]])
            ->withUploadedFiles(['items' => [0 => ['photo' => $zero], 1 => ['photo' => $one]]]);

        $response = $this->dispatch('photoList', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(PhotoListRequest::class, ValidatedController::$last);
        $this->assertSame(
            [['public' => true, 'photo' => $one], ['public' => null, 'photo' => $zero]],
            ValidatedController::$last->items,
        );
    }

    public function testKeepsARowHoldingOnlyAFileInputLeftWithoutAFile(): void
    {
        $empty   = new UploadedFile('', 0, UPLOAD_ERR_NO_FILE);
        $one     = new UploadedFile('one', 3, UPLOAD_ERR_OK, 'one.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withUploadedFiles(['items' => [0 => ['photo' => $empty], 1 => ['photo' => $one]]]);

        $response = $this->dispatch('photoList', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(PhotoListRequest::class, ValidatedController::$last);
        $this->assertSame(
            [['public' => null, 'photo' => null], ['public' => null, 'photo' => $one]],
            ValidatedController::$last->items,
        );
    }

    public function testKeepsARowHoldingOnlyAFileInputLeftWithoutAFileAfterTheRowsInTheBody(): void
    {
        $empty   = new UploadedFile('', 0, UPLOAD_ERR_NO_FILE);
        $one     = new UploadedFile('one', 3, UPLOAD_ERR_OK, 'one.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['items' => [1 => ['public' => '1']]])
            ->withUploadedFiles(['items' => [0 => ['photo' => $empty], 1 => ['photo' => $one]]]);

        $response = $this->dispatch('photoList', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(PhotoListRequest::class, ValidatedController::$last);
        $this->assertSame(
            [['public' => true, 'photo' => $one], ['public' => null, 'photo' => null]],
            ValidatedController::$last->items,
        );
    }

    public function testKeepsARowThatTheFilesHalfSendsAsAnEmptyArray(): void
    {
        $one     = new UploadedFile('one', 3, UPLOAD_ERR_OK, 'one.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withUploadedFiles(['items' => [0 => [], 1 => ['photo' => $one]]]);

        $this->dispatch('photoList', $request);

        $this->assertInstanceOf(PhotoListRequest::class, ValidatedController::$last);
        $this->assertSame(
            [['public' => null, 'photo' => null], ['public' => null, 'photo' => $one]],
            ValidatedController::$last->items,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function emptyAfterTheSanitizers(): array
    {
        return [
            'blank query' => [['name' => '  '], ['name' => 'Ann']],
            'blank body'  => [['name' => 'Ann'], ['name' => '  ']],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    #[DataProvider('emptyAfterTheSanitizers')]
    public function testJudgesAValueEmptyAfterTheSanitizersOfItsRule(array $query, array $body): void
    {
        $request = new ServerRequest('DELETE', '/dispatch')->withQueryParams($query)->withParsedBody($body);

        $response = $this->dispatch('trimmed', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(TrimmedRequest::class, ValidatedController::$last);
        $this->assertSame('Ann', ValidatedController::$last->name);
    }

    public function testLetsANestedValueBlankAfterTheSanitizersGiveWayToAFile(): void
    {
        $file    = new UploadedFile('x', 1, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['items' => [['note' => '  ']]])
            ->withUploadedFiles(['items' => [['note' => $file]]]);

        $response = $this->dispatch('trimmed', $request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['items.0.note'], array_keys(self::errors($response)));
    }

    public function testMergesBelowAPlaceWhereNoRuleIsDeclared(): void
    {
        $file    = new UploadedFile('x', 1, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['meta' => ['a' => ['x' => '1']]])
            ->withUploadedFiles(['meta' => ['a' => ['f' => $file, 'g' => $file]]]);

        $response = $this->dispatch('trimmed', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(TrimmedRequest::class, ValidatedController::$last);
        $this->assertSame(['a' => ['x' => '1', 'f' => $file, 'g' => $file]], ValidatedController::$last->meta);
    }

    public function testDoesNotMergeAKeyTheShapeDropsFromTheFiles(): void
    {
        $file    = new UploadedFile('x', 1, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $avatar  = new UploadedFile('y', 1, UPLOAD_ERR_OK, 'b.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['user' => ['name' => 'Ann', 'x' => 'a']])
            ->withUploadedFiles(['user' => ['x' => $file, 'avatar' => $avatar]]);

        $response = $this->dispatch('nestedForm', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(NestedFormRequest::class, ValidatedController::$last);
        $this->assertSame(['name' => 'Ann', 'avatar' => $avatar], ValidatedController::$last->user);
    }

    public function testMergesBelowAShapeWhoseSanitizerClosureCanRenameKeysWithoutAskingItsRules(): void
    {
        $file    = new UploadedFile('x', 1, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['user' => ['name' => 'Ann']])
            ->withUploadedFiles(['user' => ['upload' => $file]]);

        $response = $this->dispatch('renaming', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(RenamingRequest::class, ValidatedController::$last);
        $this->assertSame(['name' => 'Ann', 'avatar' => $file], ValidatedController::$last->user);
    }

    public function testIgnoresAFilesHalfHoldingOnlyKeysTheShapeDrops(): void
    {
        $file    = new UploadedFile('x', 1, UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['user' => 'abc'])
            ->withUploadedFiles(['user' => ['x' => $file]]);

        $response = $this->dispatch('nestedForm', $request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['user'], array_keys(self::errors($response)));
    }

    public function testTakesAFileInputLeftWithoutAFileAsEmptyOnlyWhereTheRuleIsAFile(): void
    {
        $empty = new UploadedFile('', 0, UPLOAD_ERR_NO_FILE);

        $nested = $this->dispatch('trimmed', new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['items' => [['note' => 'x']]])
            ->withUploadedFiles(['items' => [['note' => $empty]]]));
        $top    = $this->dispatch('trimmed', new ServerRequest('POST', '/dispatch')->withUploadedFiles(['note' => $empty]));

        $this->assertSame(400, $nested->getStatusCode());
        $this->assertSame(422, $top->getStatusCode());
        $this->assertSame(['note'], array_keys(self::errors($top)));
    }

    public function testValidatesAListInTheOrderItArrived(): void
    {
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['items' => [1 => ['public' => '1'], 0 => ['public' => '0']]]);

        $this->dispatch('photoList', $request);

        $this->assertInstanceOf(PhotoListRequest::class, ValidatedController::$last);
        $this->assertSame(
            [['public' => true, 'photo' => null], ['public' => false, 'photo' => null]],
            ValidatedController::$last->items,
        );
    }

    public function testValidatesEveryLevelOfANestedListInTheOrderItArrived(): void
    {
        $request = new ServerRequest('POST', '/dispatch')
            ->withParsedBody(['matrix' => [1 => [2 => '6', 1 => '5', 0 => '4'], 0 => [2 => '3', 1 => '2', 0 => '1']]]);

        $this->dispatch('matrix', $request);

        $this->assertInstanceOf(MatrixRequest::class, ValidatedController::$last);
        $this->assertSame([[6, 5, 4], [3, 2, 1]], ValidatedController::$last->matrix);
    }

    public function testCountsANestedFileInputLeftWithoutAFileAsSentWhenTheQueryHasTheSameField(): void
    {
        $empty   = new UploadedFile('', 0, UPLOAD_ERR_NO_FILE);
        $request = new ServerRequest('DELETE', '/dispatch')
            ->withQueryParams(['user' => ['name' => 'Ann']])
            ->withUploadedFiles(['user' => ['avatar' => $empty]]);

        $this->assertSame(400, $this->dispatch('nestedForm', $request)->getStatusCode());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function filesInputsLeftEmpty(): array
    {
        return [
            'minCount(1) fails'  => ['atLeastOnePhoto', 422],
            'required() passes'  => ['requiredPhotos', 200],
        ];
    }

    #[DataProvider('filesInputsLeftEmpty')]
    public function testReadsAMultipleFileInputLeftEmptyAsAnEmptyListAsTheValidatorDoes(string $action, int $status): void
    {
        $request = new ServerRequest('POST', '/dispatch')->withUploadedFiles(['photos' => []]);

        $this->assertSame($status, $this->dispatch($action, $request)->getStatusCode());
    }

    public function testTreatsAFileInputLeftWithoutAFileAsNotSent(): void
    {
        $empty   = new UploadedFile('', 0, UPLOAD_ERR_NO_FILE);
        $request = new ServerRequest('DELETE', '/dispatch')
            ->withQueryParams(['avatar' => 'x'])
            ->withUploadedFiles(['avatar' => $empty]);

        $response = $this->dispatch('upload', $request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['avatar'], array_keys(self::errors($response)));
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
            'initial value other than null' => ['propertyDefault', PropertyDefaultRequest::class . '::$age has an initial value, which the validated value always replaces: declare the default with default() in rules().'],
            'type differs from the rule' => ['wrongType', WrongTypeRequest::class . '::$name is declared int, but its rule gives string.'],
            'optional field not nullable' => ['notNullable', NotNullableRequest::class . '::$age must accept null (?int): the field is neither required() nor given a default().'],
            'rules() takes the Request' => ['requestInRules', RequestInRulesRequest::class . '::rules() cannot receive Sloop\Http\Request\Request: a validated request reads only its validated input.'],
            'rules() takes a ValidatedRequest' => ['validatedInRules', ValidatedInRulesRequest::class . '::rules() cannot receive ' . CreateUserRequest::class . ': a validated request reads only its validated input.'],
            'rules() takes the base class' => ['baseInRules', BaseInRulesRequest::class . '::rules() cannot receive ' . ValidatedRequest::class . ': a validated request reads only its validated input.'],
            'the base class itself' => ['base', ValidatedRequest::class . ' cannot be instantiated, so it cannot be validated into.'],
            'private property of a parent' => ['inheritedPrivate', PrivateHelperBase::class . '::$helper must be declared public private(set): every property of a validated request receives a validated value.'],
            'list() without an upper bound' => ['unboundedList', UnboundedListRequest::class . ': the list() at "items" needs maxCount(), exactCount() or betweenCount(), so that a request failing every element cannot exhaust memory.'],
            'inner list() without an upper bound' => ['innerUnboundedList', InnerUnboundedListRequest::class . ': the list() at "items.*.tags" needs maxCount(), exactCount() or betweenCount(), so that a request failing every element cannot exhaust memory.'],
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
