<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class UnionPropertyRequest extends ValidatedRequest
{
    public private(set) int|string|null $id = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['id' => Rule::int()];
    }
}
