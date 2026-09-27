<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Marker returned by a rule builder when the raw value does not have the declared type.
 *
 * A builder that can tell several kinds of rejection apart names the one it
 * means; left unnamed, the field's own type rule is reported.
 *
 * @internal Used between FieldRule and its subclasses.
 */
final readonly class TypeMismatch
{
    /**
     * Create a marker.
     *
     * @param string|null                                            $rule   Rule name to report in place of the field's type rule
     * @param array<string, int|float|string|list<int|float|string>> $params Parameters to report with $rule
     */
    public function __construct(
        public ?string $rule = null,
        public array $params = [],
    ) {
    }
}
