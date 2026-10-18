<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation\Stub;

final class Recorder
{
    /** @var list<mixed> */
    public array $seen = [];

    public function __construct(
        private readonly bool $result,
    ) {
    }

    public function test(mixed $value): bool
    {
        $this->seen[] = $value;

        return $this->result;
    }
}
