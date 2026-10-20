<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class MatrixRequest extends ValidatedRequest
{
    /** @var list<list<int|null>>|null */
    public private(set) ?array $matrix = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return [
            'matrix' => Rule::list(Rule::list(Rule::int())->maxCount(3))->maxCount(3),
        ];
    }
}
