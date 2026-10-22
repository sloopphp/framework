<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class BoolRulesRequest extends ValidatedRequest
{
    public private(set) string $name;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(bool $flag): array
    {
        RulesRecorder::$received = [$flag];

        return ['name' => Rule::string()->required()];
    }
}
