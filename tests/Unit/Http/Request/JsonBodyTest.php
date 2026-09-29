<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Request;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Sloop\Http\Request\JsonBody;

final class JsonBodyTest extends TestCase
{
    /**
     * @param array<string, string> $parsedBody
     */
    private function request(?string $contentType, ?string $body, array $parsedBody = ['form' => 'value'], string $method = 'POST'): ServerRequestInterface
    {
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];

        return new ServerRequest($method, '/', $headers, $body)->withParsedBody($parsedBody);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function jsonContentTypes(): array
    {
        return [
            'plain'                  => ['application/json'],
            'with charset'           => ['application/json; charset=utf-8'],
            'charset without space'  => ['application/json;charset=UTF-8'],
            'upper case'             => ['Application/JSON'],
            'space before the param' => ['application/json ; charset=utf-8'],
            'surrounding space'      => [' application/json '],
            'json api'               => ['application/vnd.api+json'],
            'merge patch'            => ['application/merge-patch+json'],
            'problem with charset'   => ['application/problem+json; charset=utf-8'],
            'json patch'             => ['application/json-patch+json'],
        ];
    }

    #[DataProvider('jsonContentTypes')]
    public function testParsesTheBodyOfAJsonContentType(string $contentType): void
    {
        $request = JsonBody::parse($this->request($contentType, '{"name":"Alice","tags":["a","b"]}'));

        $this->assertSame(['name' => 'Alice', 'tags' => ['a', 'b']], $request->getParsedBody());
        $this->assertFalse(JsonBody::isMalformed($request));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function otherContentTypes(): array
    {
        return [
            'no content type'  => [null],
            'form'             => ['application/x-www-form-urlencoded'],
            'multipart'        => ['multipart/form-data; boundary=x'],
            'text json'        => ['text/json'],
            'x json'           => ['application/x-json'],
            'json in a param'  => ['text/plain; format=application/json'],
            'json prefix'      => ['application/jsonx'],
            'empty subtype'    => ['application/+json'],
            'json suffix text' => ['text/vnd.foo+json'],
            'plus json only'   => ['+json'],
            'empty'            => [''],
        ];
    }

    #[DataProvider('otherContentTypes')]
    public function testLeavesTheParsedBodyOfAnotherContentTypeAlone(?string $contentType): void
    {
        $original = $this->request($contentType, '{"name":"Alice"}');

        $request = JsonBody::parse($original);

        $this->assertSame($original, $request);
        $this->assertSame(['form' => 'value'], $request->getParsedBody());
        $this->assertFalse(JsonBody::isMalformed($request));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function methods(): array
    {
        return [
            'GET'    => ['GET'],
            'PUT'    => ['PUT'],
            'PATCH'  => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    #[DataProvider('methods')]
    public function testParsesTheBodyWhateverTheMethod(string $method): void
    {
        $request = JsonBody::parse($this->request('application/json', '{"a":1}', method: $method));

        $this->assertSame(['a' => 1], $request->getParsedBody());
    }

    public function testJsonReplacesTheFormValues(): void
    {
        $request = JsonBody::parse($this->request('application/json', '{"a":1}', ['b' => '2']));

        $this->assertSame(['a' => 1], $request->getParsedBody());
    }

    public function testKeepsATopLevelList(): void
    {
        $request = JsonBody::parse($this->request('application/json', '[1, {"a": null}]'));

        $this->assertSame([1, ['a' => null]], $request->getParsedBody());
        $this->assertFalse(JsonBody::isMalformed($request));
    }

    public function testAnEmptyObjectGivesAnEmptyArray(): void
    {
        $request = JsonBody::parse($this->request('application/json', '{}'));

        $this->assertSame([], $request->getParsedBody());
        $this->assertFalse(JsonBody::isMalformed($request));
    }

    public function testAnEmptyBodyGivesAnEmptyArray(): void
    {
        $request = JsonBody::parse($this->request('application/json', ''));

        $this->assertSame([], $request->getParsedBody());
        $this->assertFalse(JsonBody::isMalformed($request));
    }

    public function testAMissingBodyGivesAnEmptyArray(): void
    {
        $request = JsonBody::parse($this->request('application/json', null));

        $this->assertSame([], $request->getParsedBody());
        $this->assertFalse(JsonBody::isMalformed($request));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedBodies(): array
    {
        return [
            'broken'          => ['{"name":'],
            'trailing comma'  => ['{"a":1,}'],
            'single quotes'   => ["{'a':1}"],
            'whitespace only' => [" \n\t"],
            'string'          => ['"Alice"'],
            'number'          => ['1'],
            'true'            => ['true'],
            'null'            => ['null'],
            'invalid utf-8'   => ["{\"a\":\"\xff\"}"],
            'too deep'        => [str_repeat('[', 512) . str_repeat(']', 512)],
        ];
    }

    #[DataProvider('malformedBodies')]
    public function testMarksABodyThatIsNotAJsonObjectOrArray(string $body): void
    {
        $request = JsonBody::parse($this->request('application/json', $body));

        $this->assertTrue(JsonBody::isMalformed($request));
        $this->assertSame([], $request->getParsedBody());
    }

    public function testReadsNestingUpToTheDepthLimit(): void
    {
        $request = JsonBody::parse($this->request('application/json', str_repeat('[', 511) . str_repeat(']', 511)));

        $this->assertFalse(JsonBody::isMalformed($request));
    }

    public function testReadsAnIntegerBeyondTheIntRangeAsAString(): void
    {
        $request = JsonBody::parse($this->request('application/json', '{"id":9223372036854775808,"small":42,"price":1.5}'));

        $this->assertSame(['id' => '9223372036854775808', 'small' => 42, 'price' => 1.5], $request->getParsedBody());
    }

    public function testReadsTheWholeBodyWhateverThePositionOfItsStream(): void
    {
        $original = $this->request('application/json', '{"a":1}');
        $original->getBody()->getContents();

        $request = JsonBody::parse($original);

        $this->assertSame(['a' => 1], $request->getParsedBody());
    }

    public function testARequestThatWasNotParsedIsNotMalformed(): void
    {
        $this->assertFalse(JsonBody::isMalformed($this->request('application/json', '{')));
    }
}
