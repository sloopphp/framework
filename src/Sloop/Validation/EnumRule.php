<?php

declare(strict_types=1);

namespace Sloop\Validation;

use BackedEnum;
use Closure;
use InvalidArgumentException;
use ReflectionEnum;

/**
 * Rules for a field whose validated value is a case of a backed enum.
 *
 * The input is the case's backing value. For an int-backed enum, a string of
 * the form `-?\d+` is read as an int first (the same rule as Rule::int()); for
 * a string-backed enum only a string is accepted. A case of the enum itself is
 * accepted as is.
 *
 * @template E of BackedEnum
 *
 * @extends FieldRule<E>
 */
final class EnumRule extends FieldRule
{
    /**
     * Whether the enum is backed by int (otherwise by string).
     *
     * @var bool
     */
    private readonly bool $intBacked;

    /**
     * Create the rule set.
     *
     * @param  class-string<E>                        $enum       Backed enum class
     * @param  list<Sanitize|Closure(string): string> $sanitizers Sanitizers applied before validation, in order
     * @throws InvalidArgumentException               When $enum is not a backed enum
     */
    public function __construct(
        private readonly string $enum,
        array $sanitizers,
    ) {
        if (!is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException('enum() needs a backed enum, got ' . $enum . '.');
        }
        $this->intBacked = (string) new ReflectionEnum($enum)->getBackingType() === 'int';
        parent::__construct($sanitizers);
    }

    /**
     * Use this case when the field is empty.
     *
     * @param  E                        $value Case to use for an empty field
     * @return self<E>
     * @throws InvalidArgumentException When the case belongs to another enum
     * @throws \LogicException          When the field is required or a default has already been declared
     */
    public function default(BackedEnum $value): self
    {
        return $this->withDefault($this->caseOf($value));
    }

    /**
     * Prepare a value a container declares as the default of this field.
     *
     * @param  mixed                    $value Value the container's default holds for this field
     * @return mixed
     * @throws InvalidArgumentException When the value is a case of another enum
     */
    protected function prepareDefault(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $this->caseOf($value) : $value;
    }

    /**
     * Fail unless the value is one of the given cases.
     *
     * @param  list<E>                  $cases   Accepted cases
     * @param  string|null              $message Message for this rule only
     * @return self<E>
     * @throws InvalidArgumentException When $cases is empty, holds a case of another enum, or the message template is malformed
     */
    public function in(array $cases, ?string $message = null): self
    {
        $this->assertCases($cases, 'in');

        return $this->withCheck('in', ['values' => self::backingValues($cases)], static fn (BackedEnum $value): bool => \in_array($value, $cases, true), $message);
    }

    /**
     * Fail when the value is one of the given cases.
     *
     * @param  list<E>                  $cases   Rejected cases
     * @param  string|null              $message Message for this rule only
     * @return self<E>
     * @throws InvalidArgumentException When $cases is empty, holds a case of another enum, or the message template is malformed
     */
    public function notIn(array $cases, ?string $message = null): self
    {
        $this->assertCases($cases, 'notIn');

        return $this->withCheck('notIn', ['values' => self::backingValues($cases)], static fn (BackedEnum $value): bool => !\in_array($value, $cases, true), $message);
    }

    /**
     * Read a case from its backing value.
     *
     * @param  mixed          $value Raw value
     * @return E|TypeMismatch
     */
    protected function coerce(mixed $value): BackedEnum|TypeMismatch
    {
        if ($value instanceof $this->enum) {
            return $value;
        }

        $backing = $this->intBacked ? NumberParser::int($value) : (\is_string($value) ? $value : null);

        return ($backing === null ? null : $this->enum::tryFrom($backing)) ?? new TypeMismatch();
    }

    /**
     * Rule name reported on a type failure.
     *
     * @return string
     */
    protected function typeRule(): string
    {
        return 'enum';
    }

    /**
     * Read a declared default as a case of this field's own enum.
     *
     * @param  BackedEnum               $value Case as the caller declared it
     * @return E
     * @throws InvalidArgumentException When the case belongs to another enum
     */
    private function caseOf(BackedEnum $value): BackedEnum
    {
        if (!$value instanceof $this->enum) {
            throw new InvalidArgumentException('default() needs a case of ' . $this->enum . ', got ' . $value::class . '.');
        }

        return $value;
    }

    /**
     * Reject an empty list or a case of another enum.
     *
     * @param  list<BackedEnum>         $cases Cases as declared
     * @param  string                   $rule  Rule name for the message
     * @return void
     * @throws InvalidArgumentException When the list is empty or holds a case of another enum
     */
    private function assertCases(array $cases, string $rule): void
    {
        if ($cases === []) {
            throw new InvalidArgumentException($rule . '() needs at least one case.');
        }
        foreach ($cases as $case) {
            if (!$case instanceof $this->enum) {
                throw new InvalidArgumentException($rule . '() needs cases of ' . $this->enum . ', got ' . $case::class . '.');
            }
        }
    }

    /**
     * Backing values of the cases, for the error parameters.
     *
     * @param  list<BackedEnum> $cases Cases
     * @return list<int|string>
     */
    private static function backingValues(array $cases): array
    {
        return array_map(static fn (BackedEnum $case): int|string => $case->value, $cases);
    }
}
