<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

use LogicException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use Sloop\Validation\FieldRule;

/**
 * Properties and rules() method of one ValidatedRequest subclass, checked against each other.
 *
 * @internal Used by RouteRequestHandler.
 */
final class ValidatedRequestShape
{
    /**
     * Shapes already read, keyed by class name.
     *
     * @var array<class-string<ValidatedRequest>, self>
     */
    private static array $shapes = [];

    /**
     * Create a shape.
     *
     * @param class-string<ValidatedRequest>                                $class      Subclass
     * @param ReflectionMethod                                              $rules      Its rules() method
     * @param array<string, array{ReflectionProperty, ReflectionNamedType}> $properties Properties receiving the values, with their types, keyed by name
     */
    private function __construct(
        private readonly string $class,
        private readonly ReflectionMethod $rules,
        private readonly array $properties,
    ) {
    }

    /**
     * Read the shape of a subclass, once per class.
     *
     * @param  class-string<ValidatedRequest> $class Subclass
     * @return self
     * @throws LogicException                 When the class cannot be instantiated, has no rules() method, or declares a property that is not a typed `public private(set)` one
     * @throws ReflectionException            When the class cannot be reflected
     */
    public static function of(string $class): self
    {
        return self::$shapes[$class] ??= self::read($class);
    }

    /**
     * Reflect a subclass and check its declarations.
     *
     * @param  class-string<ValidatedRequest> $class Subclass
     * @return self
     * @throws LogicException                 When the class cannot be instantiated, has no rules() method, or declares a property that is not a typed `public private(set)` one
     * @throws ReflectionException            When the class cannot be reflected
     */
    private static function read(string $class): self
    {
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new LogicException($class . ' cannot be instantiated, so it cannot be validated into.');
        }
        if (!$reflection->hasMethod('rules')) {
            throw new LogicException($class . ' has no rules() method.');
        }
        $rules = $reflection->getMethod('rules');
        if ($rules->isStatic()) {
            throw new LogicException($class . '::rules() must not be static.');
        }

        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            if (!$property->isPublic() || !$property->isPrivateSet() || $property->isStatic()) {
                throw new LogicException(
                    $class . '::$' . $name . ' must be declared public private(set): every property of a validated request receives a validated value.',
                );
            }
            $type = $property->getType();
            if (!$type instanceof ReflectionNamedType) {
                throw new LogicException(
                    $class . '::$' . $name . ' needs a single type (with or without ?), the type of its field\'s validated value.',
                );
            }
            $properties[$name] = [$property, $type];
        }

        return new self($class, $rules, $properties);
    }

    /**
     * Parameters of rules(), to be resolved the way an action's are.
     *
     * @return list<ReflectionParameter>
     */
    public function rulesParameters(): array
    {
        return $this->rules->getParameters();
    }

    /**
     * Call rules() and check what it returns against the properties.
     *
     * @param  ValidatedRequest                          $request   Instance to call rules() on
     * @param  list<mixed>                               $arguments Resolved arguments of rules()
     * @return array<string, FieldRule<covariant mixed>> Rules keyed by field name
     * @throws LogicException                            When rules() does not return field rules keyed by name, a rule has no property or a property no rule, or a property's type does not match its rule
     * @throws ReflectionException                       When rules() cannot be invoked
     */
    public function rules(ValidatedRequest $request, array $arguments): array
    {
        $returned = $this->rules->invokeArgs($request, $arguments);
        if (!\is_array($returned)) {
            throw new LogicException($this->class . '::rules() must return an array of field rules.');
        }

        $rules = [];
        foreach ($returned as $field => $rule) {
            if (!\is_string($field) || !$rule instanceof FieldRule) {
                throw new LogicException($this->class . '::rules() must return field rules keyed by field name.');
            }
            if (!isset($this->properties[$field])) {
                throw new LogicException($this->class . ' has a rule for "' . $field . '" but no property to receive it.');
            }
            $this->assertType($field, $rule, $this->properties[$field][1]);
            $rules[$field] = $rule;
        }
        foreach ($this->properties as $name => $_) {
            if (!isset($rules[$name])) {
                throw new LogicException($this->class . '::$' . $name . ' has no rule in rules().');
            }
        }

        return $rules;
    }

    /**
     * Fill the properties with the validated values.
     *
     * @param  ValidatedRequest     $request Instance to fill
     * @param  array<string, mixed> $values  Validated values keyed by field name; every field is present
     * @return void
     */
    public function fill(ValidatedRequest $request, array $values): void
    {
        foreach ($this->properties as $name => [$property]) {
            $property->setValue($request, $values[$name]);
        }
    }

    /**
     * Check that a property can hold every validated value of its rule.
     *
     * @param  string                     $field Field name
     * @param  FieldRule<covariant mixed> $rule  Rule of the field
     * @param  ReflectionNamedType        $type  Declared type of the property
     * @return void
     * @throws LogicException             When the type is not the rule's value type, or rejects null for a field whose value can be null
     */
    private function assertType(string $field, FieldRule $rule, ReflectionNamedType $type): void
    {
        $expected = $rule->valueType();
        if (strcasecmp($type->getName(), $expected) !== 0) {
            throw new LogicException(
                $this->class . '::$' . $field . ' is declared ' . $type->getName() . ', but its rule gives ' . $expected . '.',
            );
        }
        if ($rule->canBeNull() && !$type->allowsNull()) {
            throw new LogicException(
                $this->class . '::$' . $field . ' must accept null (?' . $expected . '): the field is neither required() nor given a default().',
            );
        }
    }
}
