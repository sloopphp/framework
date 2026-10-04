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
    'required'       => 'The {label} field is required.',
    'string'         => 'The {label} field must be a string.',
    'int'            => 'The {label} field must be an integer.',
    'float'          => 'The {label} field must be a number.',
    'bool'           => 'The {label} field must be true or false.',
    'decimal'        => 'The {label} field must be a number with at most {precision} digits, {scale} of them after the decimal point.',
    'enum'           => 'The selected {label} is invalid.',
    'date'           => 'The {label} field must be a valid date.',
    'dateTime'       => 'The {label} field must be a valid date and time with a UTC offset.',
    'file'           => 'The {label} field must be an uploaded file.',
    'upload'         => 'The {label} field failed to upload.',
    'minLength'      => 'The {label} field must be at least {unit, select, bytes {{min, plural, =1 {1 byte} other {{min, number, ::group-off} bytes}}} other {{min, plural, =1 {1 character} other {{min, number, ::group-off} characters}}}}.',
    'maxLength'      => 'The {label} field must not be longer than {unit, select, bytes {{max, plural, =1 {1 byte} other {{max, number, ::group-off} bytes}}} other {{max, plural, =1 {1 character} other {{max, number, ::group-off} characters}}}}.',
    'exactLength'    => 'The {label} field must be exactly {unit, select, bytes {{length, plural, =1 {1 byte} other {{length, number, ::group-off} bytes}}} other {{length, plural, =1 {1 character} other {{length, number, ::group-off} characters}}}}.',
    'blockSize'      => 'The {label} field length must be a multiple of {unit, select, bytes {{size, plural, =1 {1 byte} other {{size, number, ::group-off} bytes}}} other {{size, plural, =1 {1 character} other {{size, number, ::group-off} characters}}}}.',
    'regex'          => 'The {label} field format is invalid.',
    'in'             => 'The selected {label} is invalid.',
    'notIn'          => 'The selected {label} is invalid.',
    'chars'          => 'The {label} field contains characters that are not allowed.',
    'notChars'       => 'The {label} field contains characters that are not allowed.',
    'minCharClasses' => 'The {label} field must contain at least {min} kinds of characters.',
    'email'          => 'The {label} field must be a valid email address.',
    'url'            => 'The {label} field must be a valid URL.',
    'ip'             => 'The {label} field must be a valid IP address.',
    'min'            => 'The {label} field must be at least {min}.',
    'max'            => 'The {label} field must not be greater than {max}.',
    'between'        => 'The {label} field must be between {min} and {max}.',
    'before'         => 'The {label} field must be before {date}.',
    'beforeOrEqual'  => 'The {label} field must not be after {date}.',
    'after'          => 'The {label} field must be after {date}.',
    'afterOrEqual'   => 'The {label} field must not be before {date}.',
    'maxSize'        => 'The {label} field must not be larger than {max} bytes.',
    'mimeTypes'      => 'The {label} field must be a file of an accepted type.',
    'array'          => 'The {label} field must be an array.',
    'minCount'       => 'The {label} field must have at least {min, plural, =1 {1 item} other {{min, number, ::group-off} items}}.',
    'maxCount'       => 'The {label} field must not have more than {max, plural, =1 {1 item} other {{max, number, ::group-off} items}}.',
    'betweenCount'   => 'The {label} field must have between {min} and {max, plural, =1 {1 item} other {{max, number, ::group-off} items}}.',
    'exactCount'     => 'The {label} field must have exactly {count, plural, =1 {1 item} other {{count, number, ::group-off} items}}.',
    'same'           => 'The {label} field must match {other}.',
    'different'      => 'The {label} field and {other} must be different.',
];
