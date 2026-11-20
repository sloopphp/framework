<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;
use Sloop\Validation\FieldRule;
use Sloop\Validation\Rule;

final class RequestCaseInRulesRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

    /**
     * @return array<string, FieldRule<covariant mixed>>
     */
    // The wrong case is the point: PHP resolves it to the Request, under another name.
    protected function rules(\sloop\http\request\REQUEST $request): array // @phpstan-ignore class.nameCase
    {
        return ['name' => Rule::string()->default($request->psrRequest()->getUri()->getQuery())];
    }
}
