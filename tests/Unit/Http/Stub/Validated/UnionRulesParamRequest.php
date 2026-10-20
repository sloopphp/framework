<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class UnionRulesParamRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(int|string $id): array
    {
        return ['name' => Rule::string()->default((string) $id)];
    }
}
