<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

final class RulesRecorder
{
    /** @var list<mixed> */
    public static array $received = [];
}
