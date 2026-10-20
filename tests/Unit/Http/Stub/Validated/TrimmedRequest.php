<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;
use Sloop\Validation\Sanitize;

final class TrimmedRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    public private(set) ?string $note = null;

    /** @var list<array<string, mixed>>|null */
    public private(set) ?array $items = null;

    /** @var array<array-key, mixed>|null */
    public private(set) ?array $meta = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return [
            'name'  => Rule::string(Sanitize::Trim),
            'note'  => Rule::string(),
            'items' => Rule::list(Rule::shape(['photo' => Rule::file(), 'note' => Rule::string(Sanitize::Trim)]))->maxCount(5),
            'meta'  => Rule::array(),
        ];
    }
}
