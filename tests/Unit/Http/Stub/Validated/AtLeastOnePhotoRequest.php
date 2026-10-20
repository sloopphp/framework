<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class AtLeastOnePhotoRequest extends ValidatedRequest
{
    /** @var list<mixed>|null */
    public private(set) ?array $photos = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['photos' => Rule::list(Rule::file())->minCount(1)->maxCount(5)];
    }
}
