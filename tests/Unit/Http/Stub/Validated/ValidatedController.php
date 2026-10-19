<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Http\Stub\Validated;

use Sloop\Http\Request\ValidatedRequest;

final class ValidatedController
{
    public static ?object $last = null;

    public static mixed $lastId = null;

    public static function reset(): void
    {
        self::$last   = null;
        self::$lastId = null;
    }

    public function create(CreateUserRequest $request): string
    {
        self::$last = $request;

        return 'created';
    }

    public function nullableRequired(NullableRequiredRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function upload(FileUploadRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function update(InjectedRulesRequest $request, int $id): string
    {
        self::$last   = $request;
        self::$lastId = $id;

        return 'ok';
    }

    public function clocked(ClockedRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function noRules(NoRulesRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function staticRules(StaticRulesRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function mutableProperty(MutablePropertyRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function privateProperty(PrivatePropertyRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function unionProperty(UnionPropertyRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function ruleWithoutProperty(RuleWithoutPropertyRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function propertyWithoutRule(PropertyWithoutRuleRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function wrongType(WrongTypeRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function notNullable(NotNullableRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function requestInRules(RequestInRulesRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function abstract(AbstractRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function rulesNotArray(RulesNotArrayRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function rulesList(RulesListRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function rulesNotFieldRule(RulesNotFieldRuleRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function validatedInRules(ValidatedInRulesRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function unionRulesParam(UnionRulesParamRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function baseInRules(BaseInRulesRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }

    public function base(ValidatedRequest $request): string
    {
        self::$last = $request;

        return 'ok';
    }
}
