<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Request;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Error\DomainException;
use Sloop\Http\HttpStatus;
use Sloop\Http\Request\Request;

final class RequestTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param array<string, mixed>               $query
     * @param array<string, mixed>               $parsedBody
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed>               $serverParams
     * @param array<string, UploadedFile>        $uploadedFiles
     * @param array<string, string>              $routeParams
     */
    private function createRequest(
        string $method = 'GET',
        string $uri = '/',
        array $query = [],
        array $parsedBody = [],
        array $headers = [],
        array $serverParams = [],
        array $uploadedFiles = [],
        array $routeParams = [],
    ): Request {
        $psrRequest = new ServerRequest($method, new Uri($uri), $headers, null, '1.1', $serverParams);
        $psrRequest = $psrRequest->withQueryParams($query);
        $psrRequest = $psrRequest->withParsedBody($parsedBody);
        $psrRequest = $psrRequest->withUploadedFiles($uploadedFiles);

        return new Request($psrRequest, $routeParams);
    }

    private function assertBadRequest(\Closure $read, string $message): void
    {
        try {
            $read();
        } catch (DomainException $e) {
            $this->assertSame(HttpStatus::BadRequest, $e->statusCode);
            $this->assertSame($message, $e->getMessage());

            return;
        }

        $this->fail('Expected a DomainException with status 400.');
    }

    public function testGetReturnsQueryParameter(): void
    {
        $request = $this->createRequest(query: ['page' => '2', 'sort' => 'name']);

        $this->assertSame('2', $request->get('page'));
        $this->assertSame('name', $request->get('sort'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->get('missing'));
        $this->assertSame('default', $request->get('missing', 'default'));
    }

    public function testPostReturnsParsedBodyParameter(): void
    {
        $request = $this->createRequest(method: 'POST', parsedBody: ['name' => 'Alice', 'age' => '30']);

        $this->assertSame('Alice', $request->post('name'));
        $this->assertSame('30', $request->post('age'));
    }

    public function testPostReturnsDefaultForMissingKey(): void
    {
        $request = $this->createRequest(method: 'POST');

        $this->assertNull($request->post('missing'));
        $this->assertSame('fallback', $request->post('missing', 'fallback'));
    }

    public function testInputPrioritizesQueryOverBody(): void
    {
        $request = $this->createRequest(
            query: ['key' => 'from_query'],
            parsedBody: ['key' => 'from_body'],
        );

        $this->assertSame('from_query', $request->input('key'));
    }

    public function testInputFallsBackToBody(): void
    {
        $request = $this->createRequest(parsedBody: ['name' => 'Bob']);

        $this->assertSame('Bob', $request->input('name'));
    }

    public function testInputReturnsDefaultWhenNotFound(): void
    {
        $request = $this->createRequest();

        $this->assertSame('default', $request->input('missing', 'default'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonStringValueProvider(): array
    {
        return [
            'list'   => [['x']],
            'nested' => [['a' => ['b' => 'y']]],
            'int'    => [42],
            'float'  => [1.5],
            'true'   => [true],
            'false'  => [false],
        ];
    }

    #[DataProvider('nonStringValueProvider')]
    public function testGetRejectsANonStringQueryValueAsBadRequest(mixed $value): void
    {
        $request = $this->createRequest(query: ['q' => $value]);

        $this->assertBadRequest(static fn () => $request->get('q'), 'The request value "q" must be a string.');
    }

    #[DataProvider('nonStringValueProvider')]
    public function testPostRejectsANonStringBodyValueAsBadRequest(mixed $value): void
    {
        $request = $this->createRequest(method: 'POST', parsedBody: ['q' => $value]);

        $this->assertBadRequest(static fn () => $request->post('q'), 'The request value "q" must be a string.');
    }

    #[DataProvider('nonStringValueProvider')]
    public function testInputRejectsANonStringBodyValueAsBadRequest(mixed $value): void
    {
        $request = $this->createRequest(method: 'POST', parsedBody: ['q' => $value]);

        $this->assertBadRequest(static fn () => $request->input('q'), 'The request value "q" must be a string.');
    }

    public function testInputRejectsAnArrayInTheQueryWithoutFallingBackToTheBody(): void
    {
        $request = $this->createRequest(query: ['q' => ['x']], parsedBody: ['q' => 'from_body']);

        $this->assertBadRequest(static fn () => $request->input('q'), 'The request value "q" must be a string.');
    }

    public function testPostReturnsDefaultForANullBodyValue(): void
    {
        $request = $this->createRequest(method: 'POST', parsedBody: ['q' => null]);

        $this->assertSame('fallback', $request->post('q', 'fallback'));
    }

    public function testGetArrayReturnsTheQueryArrayAsIs(): void
    {
        $request = $this->createRequest(query: ['q' => ['x', 'a' => ['b' => 'y']]]);

        $this->assertSame(['x', 'a' => ['b' => 'y']], $request->getArray('q'));
    }

    public function testPostArrayReturnsTheBodyArrayWithItsJsonTypes(): void
    {
        $request = $this->createRequest(method: 'POST', parsedBody: ['tags' => [1, true, null, ['n' => 1.5]]]);

        $this->assertSame([1, true, null, ['n' => 1.5]], $request->postArray('tags'));
    }

    public function testInputArrayPrioritizesQueryOverBody(): void
    {
        $request = $this->createRequest(query: ['q' => ['from_query']], parsedBody: ['q' => ['from_body']]);

        $this->assertSame(['from_query'], $request->inputArray('q'));
    }

    public function testInputArrayFallsBackToBody(): void
    {
        $request = $this->createRequest(parsedBody: ['q' => ['from_body']]);

        $this->assertSame(['from_body'], $request->inputArray('q'));
    }

    public function testArrayAccessorsReturnDefaultForMissingOrNullValue(): void
    {
        $request = $this->createRequest(query: ['n' => null], parsedBody: ['n' => null]);

        $this->assertSame([], $request->getArray('missing'));
        $this->assertSame(['d'], $request->getArray('n', ['d']));
        $this->assertSame(['d'], $request->postArray('n', ['d']));
        $this->assertSame(['d'], $request->inputArray('missing', ['d']));
    }

    public function testPostArrayReturnsDefaultWhenBodyIsNotArray(): void
    {
        $request = $this->createRequest();

        $this->assertSame(['d'], $request->postArray('q', ['d']));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonArrayValueProvider(): array
    {
        return [
            'string' => ['x'],
            'empty'  => [''],
            'int'    => [42],
            'true'   => [true],
        ];
    }

    #[DataProvider('nonArrayValueProvider')]
    public function testArrayAccessorsRejectANonArrayValueAsBadRequest(mixed $value): void
    {
        $request = $this->createRequest(query: ['q' => $value], parsedBody: ['b' => $value]);

        $this->assertBadRequest(static fn () => $request->getArray('q'), 'The request value "q" must be an array.');
        $this->assertBadRequest(static fn () => $request->postArray('b'), 'The request value "b" must be an array.');
        $this->assertBadRequest(static fn () => $request->inputArray('q'), 'The request value "q" must be an array.');
        $this->assertBadRequest(static fn () => $request->inputArray('b'), 'The request value "b" must be an array.');
    }

    public function testJsonReturnsEntireBodyWhenKeyIsNull(): void
    {
        $request = $this->createRequest(parsedBody: ['user' => ['name' => 'Alice']]);

        $this->assertSame(['user' => ['name' => 'Alice']], $request->json());
    }

    public function testJsonReturnsDotNotationValue(): void
    {
        $request = $this->createRequest(parsedBody: [
            'user' => ['name' => 'Alice', 'address' => ['city' => 'Tokyo']],
        ]);

        $this->assertSame('Alice', $request->json('user.name'));
        $this->assertSame('Tokyo', $request->json('user.address.city'));
    }

    public function testJsonReturnsDefaultForMissingKey(): void
    {
        $request = $this->createRequest(parsedBody: ['user' => ['name' => 'Alice']]);

        $this->assertNull($request->json('user.email'));
        $this->assertSame('N/A', $request->json('user.email', 'N/A'));
    }

    public function testJsonReturnsEmptyArrayWhenBodyIsNotArray(): void
    {
        $psrRequest = new ServerRequest('POST', new Uri('/'));
        $request    = new Request($psrRequest);

        $this->assertSame([], $request->json());
    }

    public function testIpReturnsRemoteAddr(): void
    {
        $request = $this->createRequest(serverParams: ['REMOTE_ADDR' => '192.168.1.1']);

        $this->assertSame('192.168.1.1', $request->ip());
    }

    public function testIpReturnsNullWhenNotSet(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->ip());
    }

    public function testHeaderReturnsValue(): void
    {
        $request = $this->createRequest(headers: ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $request->header('Content-Type'));
    }

    public function testHeaderReturnsDefaultForMissingHeader(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->header('X-Custom'));
        $this->assertSame('default', $request->header('X-Custom', 'default'));
    }

    public function testIsAjaxReturnsTrueForXhr(): void
    {
        $request = $this->createRequest(headers: ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertTrue($request->isAjax());
    }

    public function testIsAjaxReturnsFalseForNonAjaxRequest(): void
    {
        $request = $this->createRequest();

        $this->assertFalse($request->isAjax());
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function extensionProvider(): array
    {
        return [
            'json extension'    => ['/users.json', 'json'],
            'xml extension'     => ['/api/data.xml', 'xml'],
            'csv extension'     => ['/export.csv', 'csv'],
            'no extension'      => ['/users', null],
            'no extension root' => ['/', null],
            'dot in directory'  => ['/api.v1/users', null],
            'hidden file'       => ['/.env', null],
        ];
    }

    #[DataProvider('extensionProvider')]
    public function testExtensionExtractsFromPath(string $uri, ?string $expected): void
    {
        $request = $this->createRequest(uri: $uri);

        $this->assertSame($expected, $request->extension());
    }

    public function testFileReturnsUploadedFile(): void
    {
        $stream       = $this->factory->createStream('file contents');
        $uploadedFile = new UploadedFile($stream, $stream->getSize() ?? 0, UPLOAD_ERR_OK, 'test.txt', 'text/plain');
        $request      = $this->createRequest(uploadedFiles: ['avatar' => $uploadedFile]);

        $this->assertSame($uploadedFile, $request->file('avatar'));
    }

    public function testFileReturnsNullForMissingFile(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->file('avatar'));
    }

    public function testMethodReturnsHttpMethod(): void
    {
        // Default method is GET
        $this->assertSame('GET', $this->createRequest()->method());
        $this->assertSame('POST', $this->createRequest(method: 'POST')->method());
        $this->assertSame('PUT', $this->createRequest(method: 'PUT')->method());
        $this->assertSame('DELETE', $this->createRequest(method: 'DELETE')->method());
    }

    public function testAuthorizationParsesBearerScheme(): void
    {
        $request = $this->createRequest(headers: ['Authorization' => 'Bearer abc123xyz']);

        $this->assertSame(['scheme' => 'bearer', 'credentials' => 'abc123xyz'], $request->authorization());
    }

    public function testAuthorizationParsesBasicScheme(): void
    {
        $request = $this->createRequest(headers: ['Authorization' => 'Basic dXNlcjpwYXNz']);

        $this->assertSame(['scheme' => 'basic', 'credentials' => 'dXNlcjpwYXNz'], $request->authorization());
    }

    public function testAuthorizationNormalizesSchemeCase(): void
    {
        $request = $this->createRequest(headers: ['Authorization' => 'BEARER token123']);

        $this->assertSame(['scheme' => 'bearer', 'credentials' => 'token123'], $request->authorization());
    }

    public function testAuthorizationReturnsNullWithoutHeader(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->authorization());
    }

    public function testAuthorizationReturnsNullForMalformedHeader(): void
    {
        $request = $this->createRequest(headers: ['Authorization' => 'MalformedNoSpace']);

        $this->assertNull($request->authorization());
    }

    public function testBearerTokenExtractsToken(): void
    {
        $request = $this->createRequest(headers: ['Authorization' => 'Bearer abc123xyz']);

        $this->assertSame('abc123xyz', $request->bearerToken());
    }

    public function testBearerTokenReturnsNullWithoutAuthorizationHeader(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->bearerToken());
    }

    public function testBearerTokenReturnsNullForNonBearerAuth(): void
    {
        $request = $this->createRequest(headers: ['Authorization' => 'Basic dXNlcjpwYXNz']);

        $this->assertNull($request->bearerToken());
    }

    public function testParamReturnsRouteParameter(): void
    {
        $request = $this->createRequest(routeParams: ['id' => '42', 'slug' => 'hello-world']);

        $this->assertSame('42', $request->param('id'));
        $this->assertSame('hello-world', $request->param('slug'));
    }

    public function testParamReturnsDefaultForMissingParam(): void
    {
        $request = $this->createRequest();

        $this->assertNull($request->param('id'));
        $this->assertSame('0', $request->param('id', '0'));
    }

    public function testPsrRequestReturnsUnderlyingRequest(): void
    {
        $psrRequest = new ServerRequest('GET', new Uri('/'));
        $request    = new Request($psrRequest);

        $this->assertSame($psrRequest, $request->psrRequest());
    }

    // ---------------------------------------------------------------
    // Defensive guard / edge case coverage
    // ---------------------------------------------------------------

    public function testPostReturnsDefaultWhenBodyIsNotArray(): void
    {
        // PSR-7 getParsedBody() can return null, array, or object.
        // When null (e.g., GET request), post() must fall back to default.
        $request = $this->createRequest();

        $this->assertNull($request->post('key'));
        $this->assertSame('fallback', $request->post('key', 'fallback'));
    }

    public function testJsonCacheReturnsSameResultOnSecondCall(): void
    {
        $request = $this->createRequest(parsedBody: ['name' => 'Alice']);

        $first  = $request->json();
        $second = $request->json();

        $this->assertSame($first, $second);
        $this->assertSame('Alice', $request->json('name'));
    }

    public function testAuthorizationPreservesSpacesInCredentials(): void
    {
        // AWS Signature V4: "AWS4-HMAC-SHA256 Credential=xxx, SignedHeaders=yyy, Signature=zzz"
        $header  = 'AWS4-HMAC-SHA256 Credential=xxx, SignedHeaders=yyy, Signature=zzz';
        $request = $this->createRequest(headers: ['Authorization' => $header]);
        $auth    = $request->authorization();

        $this->assertNotNull($auth);
        $this->assertSame('aws4-hmac-sha256', $auth['scheme']);
        $this->assertSame('Credential=xxx, SignedHeaders=yyy, Signature=zzz', $auth['credentials']);
    }

    public function testIpReturnsNullForNonStringRemoteAddr(): void
    {
        // Defensive guard: REMOTE_ADDR could theoretically be non-string
        // in edge cases (custom server params).
        $request = $this->createRequest(serverParams: ['REMOTE_ADDR' => 12345]);

        $this->assertNull($request->ip());
    }

    // Note: the instanceof UploadedFileInterface guard in file() cannot be
    // reproduced type-safely because PSR-7's withUploadedFiles() requires a
    // typed array. A PHPStan suppression is forbidden by project policy, so
    // this is left untested. The one-line instanceof check in Request::file()
    // is forward-compat defensive code.

    public function testBearerTokenIsCaseInsensitive(): void
    {
        // Authorization scheme is normalized to lowercase by authorization(),
        // so "BEARER token" should still return the token via bearerToken().
        $request = $this->createRequest(headers: ['Authorization' => 'BEARER my-token']);

        $this->assertSame('my-token', $request->bearerToken());
    }

    public function testHeaderIsCaseInsensitive(): void
    {
        // PSR-7 guarantees case-insensitive header lookup.
        $request = $this->createRequest(headers: ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $request->header('content-type'));
        $this->assertSame('application/json', $request->header('CONTENT-TYPE'));
    }
}
