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
     * Reader returning the body cut to the length asked for, recording that length.
     *
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

    public function testARequestNotReadByItIsNotTooLarge(): void
    {
        $this->assertFalse(RawBody::isTooLarge(new ServerRequest('POST', '/')));
    }
}
