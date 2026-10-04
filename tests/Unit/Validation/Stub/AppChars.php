<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation\Stub;

use Sloop\Validation\CharSet;

enum AppChars: string implements CharSet
{
    case Circled = 'circled';
    case Greek   = 'greek';

    public function pattern(): string
    {
        return match ($this) {
            self::Circled => '\x{2460}-\x{2473}',
            self::Greek   => '\p{Greek}',
        };
    }

    public function name(): string
    {
        return $this->value;
    }
}
