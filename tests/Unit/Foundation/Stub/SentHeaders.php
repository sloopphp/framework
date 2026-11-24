<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Foundation\Stub;

final class SentHeaders
{
    /** @var list<array{string, bool}> */
    public static array $lines = [];

    public static ?int $status = null;
}
