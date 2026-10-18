<?php

declare(strict_types=1);

namespace Sloop\Encryption\Exception;

use LogicException;

/**
 * Thrown when the encryption config is missing or malformed.
 *
 * Indicates a programmer error in config files: a key that is not 64
 * hexadecimal characters, an unknown cipher name, or a missing key when
 * encryption is used. A payload that cannot be decrypted is not an error
 * of this kind; Encryptor::decrypt() returns false for it.
 */
final class InvalidConfigException extends LogicException
{
}
