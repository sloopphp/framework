<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Request;

use Closure;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RequestParseBodyException;
use Sloop\Http\Request\FormBody;

final class FormBodyTest extends TestCase
{
    private function request(string $method, ?string $contentType): ServerRequestInterface
    {
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];

        return new ServerRequest($method, '/', $headers)->withParsedBody(['global' => 'value']);
    }

    /**
     * @param  array<array-key, mixed>                        $values
     * @param  array<array-key, mixed>                        $files
     * @return Closure(): array<int, array<array-key, mixed>>
     */
    private static function parser(array $values, array $files = []): Closure
    {
        return static fn (): array => [$values, $files];
    }

    /**
     * @return Closure(): array<int, array<array-key, mixed>>
     */
    private static function unreachableParser(): Closure
    {
        return static function (): never {
            self::fail('The body was parsed.');
        };
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parsedMethods(): array
    {
        return [
            'PUT'    => ['PUT'],
            'PATCH'  => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    #[DataProvider('parsedMethods')]
    public function testParsesTheFormBodyOfPutPatchAndDelete(string $method): void
    {
        $request = FormBody::parse(
            $this->request($method, 'application/x-www-form-urlencoded'),
            self::parser(['name' => 'Alice']),
        );

        $this->assertSame(['name' => 'Alice'], $request->getParsedBody());
        $this->assertFalse(FormBody::isMalformed($request));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherMethods(): array
    {
        return [
            'POST'      => ['POST'],
            'GET'       => ['GET'],
            'HEAD'      => ['HEAD'],
            'OPTIONS'   => ['OPTIONS'],
            'lower put' => ['put'],
        ];
    }

    #[DataProvider('otherMethods')]
    public function testLeavesAnotherMethodAlone(string $method): void
    {
        $request = FormBody::parse(
            $this->request($method, 'application/x-www-form-urlencoded'),
            self::unreachableParser(),
        );

        $this->assertSame(['global' => 'value'], $request->getParsedBody());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formContentTypes(): array
    {
        return [
            'urlencoded'              => ['application/x-www-form-urlencoded'],
            'urlencoded with charset' => ['application/x-www-form-urlencoded; charset=UTF-8'],
            'multipart with boundary' => ['multipart/form-data; boundary=XX'],
            'upper case'              => ['Multipart/Form-Data; boundary=XX'],
            'surrounding space'       => [' application/x-www-form-urlencoded '],
            'space before the param'  => ['application/x-www-form-urlencoded ; charset=UTF-8'],
        ];
    }

    #[DataProvider('formContentTypes')]
    public function testParsesEachFormContentType(string $contentType): void
    {
        $request = FormBody::parse($this->request('PUT', $contentType), self::parser(['a' => '1']));

        $this->assertSame(['a' => '1'], $request->getParsedBody());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function otherContentTypes(): array
    {
        return [
            'no content type' => [null],
            'json'            => ['application/json'],
            'text'            => ['text/plain'],
            'form as suffix'  => ['application/x-www-form-urlencoded-extra'],
        ];
    }

    #[DataProvider('otherContentTypes')]
    public function testLeavesAnotherContentTypeAlone(?string $contentType): void
    {
        $request = FormBody::parse($this->request('PUT', $contentType), self::unreachableParser());

        $this->assertSame(['global' => 'value'], $request->getParsedBody());
        $this->assertFalse(FormBody::isMalformed($request));
    }

    public function testTheFilesOfAMultipartBodyBecomeUploadedFiles(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'formbody');
        $this->assertIsString($tmp);
        file_put_contents($tmp, 'hi');

        try {
            $request = FormBody::parse(
                $this->request('PUT', 'multipart/form-data; boundary=XX'),
                self::parser(['a' => '1'], [
                    'avatar' => ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => \UPLOAD_ERR_OK, 'size' => 2],
                    'empty'  => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 0],
                ]),
            );

            $files = $request->getUploadedFiles();
            $this->assertSame(['avatar'], array_keys($files));
            $this->assertInstanceOf(UploadedFileInterface::class, $files['avatar']);
            $this->assertSame('a.txt', $files['avatar']->getClientFilename());
            $this->assertSame(2, $files['avatar']->getSize());
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testMarksABodyPhpRefusedToRead(): void
    {
        $request = FormBody::parse(
            $this->request('PATCH', 'multipart/form-data'),
            static function (): never {
                throw new RequestParseBodyException('Missing boundary in multipart/form-data POST data');
            },
        );

        $this->assertTrue(FormBody::isMalformed($request));
        $this->assertSame([], $request->getParsedBody());
    }

    public function testAParserThatReturnsNothingGivesAnEmptyBody(): void
    {
        $request = FormBody::parse(
            $this->request('PUT', 'application/x-www-form-urlencoded'),
            static fn (): array => [],
        );

        $this->assertSame([], $request->getParsedBody());
        $this->assertSame([], $request->getUploadedFiles());
    }

    public function testARequestThatWasNotParsedIsNotMalformed(): void
    {
        $this->assertFalse(FormBody::isMalformed($this->request('PUT', 'application/x-www-form-urlencoded')));
    }
}
