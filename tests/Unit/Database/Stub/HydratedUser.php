<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Stub;

final readonly class HydratedUser
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email = null,
    ) {
    }
}
