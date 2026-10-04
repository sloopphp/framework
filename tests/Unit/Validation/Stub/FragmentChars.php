<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation\Stub;

use Sloop\Validation\CharSet;

final readonly class FragmentChars implements CharSet
{
    public function __construct(
        private string $pattern,
        private string $name = 'fragment',
    ) {
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function name(): string
    {
        return $this->name;
    }
}
