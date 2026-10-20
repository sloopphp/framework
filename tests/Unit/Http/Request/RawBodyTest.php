<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Request;

use Closure;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Http\Request\RawBody;

final class RawBodyTest extends TestCase
{
    /**
     * @param \ArrayObject<int, int> $asked
     */
    private function reader(string|false $body, \ArrayObject $asked = new \ArrayObject()): Closure
    {
        return static function (int $maxLength) use ($body, $asked): string|false {
            $asked->append($maxLength);

            return $body === false ? false : substr($body, 0, $maxLength);
        };
    }

    public function testAttachesABodyWithinTheLimit(): void
    {
        $request = RawBody::read(new ServerRequest('POST', '/'), $this->reader('{"a":1}'), 7);

        $this->assertSame('{"a":1}', (string) $request->getBody());
        $this->assertFalse(RawBody::isTooLarge($request));
    }

    public function testReadsOneByteMoreThanTheLimitAndNoMore(): void
    {
        $asked = new \ArrayObject();
        RawBody::read(new ServerRequest('POST', '/'), $this->reader(str_repeat('a', 100), $asked), 10);

        $this->assertSame([11], $asked->getArrayCopy());
    }

    public function testMarksABodyLongerThanTheLimitWithoutAttachingIt(): void
    {
        $request = RawBody::read(new ServerRequest('POST', '/'), $this->reader('12345678'), 7);

        $this->assertTrue(RawBody::isTooLarge($request));
        $this->assertSame('', (string) $request->getBody());
    }

    /**
     * @return array<string, array{string|false}>
     */
    public static function noBodyProvider(): array
    {
        return [
            'empty body'           => [''],
            'unreadable php input' => [false],
        ];
    }

    #[DataProvider('noBodyProvider')]
    public function testLeavesTheRequestAsItIsWithoutABody(string|false $body): void
    {
        $original = new ServerRequest('POST', '/');

        $request = RawBody::read($original, $this->reader($body), 7);

        $this->assertSame($original, $request);
        $this->assertFalse(RawBody::isTooLarge($request));
    }

    public function testReadsUpToTheLimitWhenTheLimitIsTheLargestInteger(): void
    {
        $asked   = new \ArrayObject();
        $request = RawBody::read(new ServerRequest('POST', '/'), $this->reader('{"a":1}', $asked), \PHP_INT_MAX);

        $this->assertSame([\PHP_INT_MAX], $asked->getArrayCopy());
        $this->assertSame('{"a":1}', (string) $request->getBody());
        $this->assertFalse(RawBody::isTooLarge($request));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, int<0, max>, bool}>
     */
    public static function parsedFieldsProvider(): array
    {
        return [
            'name and value at the limit'      => [['ab' => 'cde'], 5, false],
            'name and value past the limit'    => [['ab' => 'cdef'], 5, true],
            'nested fields past the limit'     => [['a' => ['b' => 'cd', 'e' => 'f']], 5, true],
            'nested fields at the limit'       => [['a' => ['b' => 'c'], 'd' => ''], 4, false],
            'numeric names count their digits' => [[10 => 'abc'], 4, true],
            'no fields with a limit of zero'   => [[], 0, false],
            'a value that is not a string adds nothing' => [['abc' => 1, 'd' => 'xy'], 5, true],
        ];
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param int<0, max>             $limit
     */
    #[DataProvider('parsedFieldsProvider')]
    public function testCountsTheParsedFieldsOfAMultipartBodyPhpAlreadyRead(array $fields, int $limit, bool $tooLarge): void
    {
        $request = RawBody::read(new ServerRequest('POST', '/')->withParsedBody($fields), $this->reader(''), $limit);

        $this->assertSame($tooLarge, RawBody::isTooLarge($request));
    }

    public function testABodyWithoutParsedFieldsIsNotTooLargeAtALimitOfZero(): void
    {
        $request = RawBody::read(new ServerRequest('POST', '/'), $this->reader(''), 0);

        $this->assertFalse(RawBody::isTooLarge($request));
    }

    public function testARequestNotReadByItIsNotTooLarge(): void
    {
        $this->assertFalse(RawBody::isTooLarge(new ServerRequest('POST', '/')));
    }
}
