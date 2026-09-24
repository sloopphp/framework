<?php

declare(strict_types=1);

/*
 * Default validation messages, keyed by rule name.
 *
 * ICU MessageFormat: {label} is the field's label, the other placeholders are
 * the rule's parameters. Quote a value with double quotes, never with a single
 * quote before "{" (ICU would print the placeholder literally).
 * An application overrides entries key by key with its own lang/en/validation.php.
 */
return [
    'required'      => 'The {label} field is required.',
    'string'        => 'The {label} field must be a string.',
    'int'           => 'The {label} field must be an integer.',
    'float'         => 'The {label} field must be a number.',
    'bool'          => 'The {label} field must be true or false.',
    'decimal'       => 'The {label} field must be a number with at most {precision} digits, {scale} of them after the decimal point.',
    'enum'          => 'The selected {label} is invalid.',
    'date'          => 'The {label} field must be a valid date.',
    'dateTime'      => 'The {label} field must be a valid date and time with a UTC offset.',
    'minLength'     => 'The {label} field must be at least {min} characters.',
    'maxLength'     => 'The {label} field must not be longer than {max} characters.',
    'exactLength'   => 'The {label} field must be exactly {length} characters.',
    'regex'         => 'The {label} field format is invalid.',
    'in'            => 'The selected {label} is invalid.',
    'notIn'         => 'The selected {label} is invalid.',
    'chars'         => 'The {label} field contains characters that are not allowed.',
    'email'         => 'The {label} field must be a valid email address.',
    'url'           => 'The {label} field must be a valid URL.',
    'ip'            => 'The {label} field must be a valid IP address.',
    'min'           => 'The {label} field must be at least {min}.',
    'max'           => 'The {label} field must not be greater than {max}.',
    'between'       => 'The {label} field must be between {min} and {max}.',
    'before'        => 'The {label} field must be before {date}.',
    'beforeOrEqual' => 'The {label} field must not be after {date}.',
    'after'         => 'The {label} field must be after {date}.',
    'afterOrEqual'  => 'The {label} field must not be before {date}.',
    'array'         => 'The {label} field must be an array.',
    'minCount'      => 'The {label} field must have at least {min} items.',
    'maxCount'      => 'The {label} field must not have more than {max} items.',
    'betweenCount'  => 'The {label} field must have between {min} and {max} items.',
    'exactCount'    => 'The {label} field must have exactly {count} items.',
    'same'          => 'The {label} field must match {other}.',
    'different'     => 'The {label} field and {other} must be different.',
];
