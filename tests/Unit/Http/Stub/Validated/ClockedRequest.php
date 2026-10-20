<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use DateTimeImmutable;
use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class ClockedRequest extends ValidatedRequest
{
    public private(set) DateTimeImmutable $at;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['at' => Rule::dateTime()->required()->afterNow()];
    }
}
