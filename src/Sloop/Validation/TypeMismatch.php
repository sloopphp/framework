<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * Marker returned by a rule builder when the raw value does not have the declared type.
 *
 * @internal Used between FieldRule and its subclasses.
 */
final class TypeMismatch
{
}
