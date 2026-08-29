<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database\Stub;

use DateTimeImmutable;

// The shape docs/ja/database.md documents for hydration: the columns are
// declared with the types the driver actually returns, and the one conversion
// the framework does not do — DATETIME to a date object — happens here.
final readonly class SelectedUser
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public int $id,
        public string $name,
        public int $score,
        string $created_at,
        public string $status = 'unknown',
    ) {
        $this->createdAt = new DateTimeImmutable($created_at);
    }
}
