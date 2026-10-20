<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class ValidatedInRulesRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(CreateUserRequest $other): array
    {
        return ['name' => Rule::string()->default($other->name)];
    }
}
