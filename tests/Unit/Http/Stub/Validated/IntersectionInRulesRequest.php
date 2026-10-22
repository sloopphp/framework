<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Countable;
use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class IntersectionInRulesRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules((Countable&CreateUserRequest)|null $source = null): array
    {
        return ['name' => Rule::string()->default($source === null ? 'null' : 'given')];
    }
}
