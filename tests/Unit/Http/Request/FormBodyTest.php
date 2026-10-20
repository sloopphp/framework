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
     * @param  array<array-key, mixed>                                             $values
     * @param  array<array-key, mixed>                                             $files
     * @return Closure(array<string, string>): array<int, array<array-key, mixed>>
     */
    private static function parser(array $values, array $files = []): Closure
    {
        return static fn (): array => [$values, $files];
    }

    /**
     * @return Closure(array<string, string>): array<int, array<array-key, mixed>>
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
            1_048_576,
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
            1_048_576,
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
        $request = FormBody::parse($this->request('PUT', $contentType), self::parser(['a' => '1']), 1_048_576);

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
        $request = FormBody::parse($this->request('PUT', $contentType), self::unreachableParser(), 1_048_576);

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
                1_048_576,
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
            1_048_576,
        );

        $this->assertTrue(FormBody::isMalformed($request));
        $this->assertSame([], $request->getParsedBody());
    }

    public function testAParserThatReturnsNothingGivesAnEmptyBody(): void
    {
        $request = FormBody::parse(
            $this->request('PUT', 'application/x-www-form-urlencoded'),
            static fn (): array => [],
            1_048_576,
        );

        $this->assertSame([], $request->getParsedBody());
        $this->assertSame([], $request->getUploadedFiles());
    }

    public function testARequestThatWasNotParsedIsNotMalformed(): void
    {
        $this->assertFalse(FormBody::isMalformed($this->request('PUT', 'application/x-www-form-urlencoded')));
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function parserOptionsProvider(): array
    {
        return [
            'urlencoded is parsed up to the limit'  => ['application/x-www-form-urlencoded', ['post_max_size' => '512']],
            'multipart keeps PHP\'s post_max_size' => ['multipart/form-data; boundary=x', []],
        ];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('parserOptionsProvider')]
    public function testGivesTheParserTheLimitForUrlencodedBodiesOnly(string $contentType, array $expected): void
    {
        $given = new \ArrayObject();

        FormBody::parse(
            $this->request('PUT', $contentType),
            static function (array $options) use ($given): array {
                $given->exchangeArray($options);

                return [[], []];
            },
            512,
        );

        $this->assertSame($expected, $given->getArrayCopy());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overLimitWarningProvider(): array
    {
        return [
            'with a Content-Length'    => ['POST Content-Length of 3002 bytes exceeds the limit of 100 bytes'],
            'without a Content-Length' => ['Actual POST length does not match Content-Length, and exceeds 100 bytes'],
        ];
    }

    #[DataProvider('overLimitWarningProvider')]
    public function testKeepsTheWarningThatTheBodyIsOverTheLimitOutOfTheOutput(string $warning): void
    {
        error_clear_last();

        $request = FormBody::parse(
            $this->request('PUT', 'application/x-www-form-urlencoded'),
            static function () use ($warning): array {
                // A warning whose message carries the words request_parse_body()
                // uses when the body is over post_max_size.
                file_get_contents('/nonexistent/' . $warning);

                return [[], []];
            },
            1_048_576,
        );

        $this->assertSame([], $request->getParsedBody());
        $this->assertNull(error_get_last());
    }

    public function testLetsAnotherWarningOfTheParserThrough(): void
    {
        error_clear_last();
        $display = ini_set('display_errors', '0');
        $log     = ini_set('log_errors', '0');

        try {
            FormBody::parse(
                $this->request('PUT', 'application/x-www-form-urlencoded'),
                static function (): array {
                    // Stands in for the warning that input variables were dropped.
                    hex2bin('a');

                    return [[], []];
                },
                1_048_576,
            );
        } finally {
            ini_set('display_errors', (string) $display);
            ini_set('log_errors', (string) $log);
        }

        $this->assertStringContainsString('Hexadecimal input string', error_get_last()['message'] ?? '');
    }
}
