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
     * @throws LogicException                 When the class cannot be instantiated, has no rules() method, or declares a property that is not a typed `public private(set)` one or gives one an initial value other than null
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
     * @throws LogicException                 When the class cannot be instantiated, has no rules() method, or declares a property that is not a typed `public private(set)` one or gives one an initial value other than null
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
        foreach (self::declaredProperties($reflection) as $property) {
            $properties[$property->getName()] = [$property, self::receivingType($property)];
        }

        return new self($class, $rules, $properties);
    }

    /**
     * Every property of a subclass, the private ones of its parents between it and ValidatedRequest included.
     *
     * @param  ReflectionClass<ValidatedRequest> $reflection Subclass
     * @return list<ReflectionProperty>
     */
    private static function declaredProperties(ReflectionClass $reflection): array
    {
        $declared = $reflection->getProperties();
        for ($parent = $reflection->getParentClass(); $parent !== false && $parent->getName() !== ValidatedRequest::class; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties(ReflectionProperty::IS_PRIVATE) as $private) {
                $declared[] = $private;
            }
        }

        return $declared;
    }

    /**
     * Check how a property is declared, and return its type.
     *
     * @param  ReflectionProperty  $property Property of the subclass or of a parent
     * @return ReflectionNamedType
     * @throws LogicException      When the property is not a typed `public private(set)` one, or has an initial value other than null
     */
    private static function receivingType(ReflectionProperty $property): ReflectionNamedType
    {
        $where = $property->getDeclaringClass()->getName() . '::$' . $property->getName();
        if (!$property->isPublic() || !$property->isPrivateSet() || $property->isStatic()) {
            throw new LogicException(
                $where . ' must be declared public private(set): every property of a validated request receives a validated value.',
            );
        }
        if ($property->hasDefaultValue() && $property->getDefaultValue() !== null) {
            throw new LogicException(
                $where . ' has an initial value, which the validated value always replaces: declare the default with default() in rules().',
            );
        }
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new LogicException(
                $where . ' needs a single type (with or without ?), the type of its field\'s validated value.',
            );
        }

        return $type;
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
     * @throws LogicException                            When rules() does not return field rules keyed by name, a rule has no property or a property no rule, a property's type does not match its rule, or a list() has no upper bound on its count
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
            $unbounded = $rule->unboundedList();
            if ($unbounded !== null) {
                throw new LogicException(
                    $this->class . ': the list() at "' . ($unbounded === '' ? $field : $field . '.' . $unbounded) . '" needs maxCount(), exactCount() or betweenCount(), '
                    . 'so that a request failing every element cannot exhaust memory.',
                );
            }
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
