<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;

final class NoRulesRequest extends ValidatedRequest
{
    public private(set) ?string $name = null;

}
