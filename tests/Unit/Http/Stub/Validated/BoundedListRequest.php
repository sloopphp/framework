<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class BoundedListRequest extends ValidatedRequest
{
    /** @var list<mixed>|null */
    public private(set) ?array $items = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['items' => Rule::list(Rule::list(Rule::int())->betweenCount(1, 3))->exactCount(2)];
    }
}
