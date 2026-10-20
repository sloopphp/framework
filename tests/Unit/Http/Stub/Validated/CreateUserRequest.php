<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class CreateUserRequest extends ValidatedRequest
{
    public private(set) string $name;
    public private(set) ?int $age = null;
    public private(set) int $limit;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return [
            'name'  => Rule::string()->required()->maxLength(5),
            'age'   => Rule::int(),
            'limit' => Rule::int()->default(10),
        ];
    }
}
