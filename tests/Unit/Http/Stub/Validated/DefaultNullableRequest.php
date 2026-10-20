<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class DefaultNullableRequest extends ValidatedRequest
{
    public private(set) ?int $limit = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['limit' => Rule::int()->default(10)];
    }
}
