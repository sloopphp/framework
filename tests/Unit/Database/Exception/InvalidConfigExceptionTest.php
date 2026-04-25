<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Exception;

use LogicException;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Exception\InvalidConfigException;

final class InvalidConfigExceptionTest extends TestCase
{
    public function testExtendsLogicException(): void
    {
        // Also implicitly guards against accidentally being moved under
        // DatabaseException (RuntimeException) — LogicException and
        // RuntimeException are mutually exclusive PHP SPL hierarchies.
        $this->assertInstanceOf(LogicException::class, new InvalidConfigException('test'));
    }
}
