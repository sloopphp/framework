<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Tests\Unit\Http\Stub\DiService;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class InjectedRulesRequest extends ValidatedRequest
{
    public private(set) string $name;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    protected function rules(DiService $service, int $id): array
    {
        RulesRecorder::$received = [$service->id, $id];

        return ['name' => Rule::string()->required()];
    }
}
