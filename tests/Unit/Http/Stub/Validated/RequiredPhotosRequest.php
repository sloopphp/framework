<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class RequiredPhotosRequest extends ValidatedRequest
{
    /** @var list<mixed> */
    public private(set) array $photos;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(): array
    {
        return ['photos' => Rule::list(Rule::file())->required()->maxCount(5)];
    }
}
