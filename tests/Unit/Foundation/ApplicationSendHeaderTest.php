<?php

declare(strict_types=1);

namespace Sloop\Foundation {
    use Sloop\Tests\Unit\Foundation\Stub\SentHeaders;

    /**
     * Stand-in for the global header(), which the CLI ignores.
     *
     * Application calls header() unqualified, so this function in its namespace
     * is the one it reaches.
     *
     * @param  string $header  Header line
     * @param  bool   $replace Whether to replace an earlier header of the same name
     * @return void
     */
    function header(string $header, bool $replace = true): void
    {
        SentHeaders::$lines[] = [$header, $replace];
    }

    /**
     * Stand-in for the global http_response_code(), so the test process keeps its own.
     *
     * @param  int  $code Status code Application sets
     * @return bool
     */
    function http_response_code(int $code): bool
    {
        SentHeaders::$status = $code;

        return true;
    }
}

namespace Sloop\Tests\Unit\Foundation {
    use Nyholm\Psr7\Response;
    use PHPUnit\Framework\TestCase;
    use Sloop\Foundation\Application;
    use Sloop\Tests\Unit\Foundation\Stub\SentHeaders;

    final class ApplicationSendHeaderTest extends TestCase
    {
        private string $tmpDir;

        protected function setUp(): void
        {
            $this->tmpDir = sys_get_temp_dir() . '/sloop_send_header_test_' . bin2hex(random_bytes(8));
            mkdir($this->tmpDir);
            SentHeaders::$lines  = [];
            SentHeaders::$status = null;
        }

        protected function tearDown(): void
        {
            SentHeaders::$lines  = [];
            SentHeaders::$status = null;
            rmdir($this->tmpDir);
        }

        public function testSendSetsTheStatusWritesEveryHeaderValueWithoutReplacingAndEchoesTheBody(): void
        {
            $app = new Application($this->tmpDir);

            $this->expectOutputString('payload');
            $app->send(new Response(201, ['X-One' => ['a', 'b'], 'X-Two' => 'c'], 'payload'));

            $this->assertSame(201, SentHeaders::$status);
            $this->assertSame(
                [['X-One: a', false], ['X-One: b', false], ['X-Two: c', false]],
                SentHeaders::$lines,
            );
        }
    }
}
