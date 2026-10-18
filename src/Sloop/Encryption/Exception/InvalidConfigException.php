<?php

declare(strict_types=1);

namespace Sloop\Encryption\Exception;

use LogicException;

/**
 * Thrown when the encryption, signing or hashing config is missing or malformed.
 *
 * Indicates a programmer error in config files: a key that is not 64
 * hexadecimal characters, an unknown cipher or hashing algorithm name, a
 * missing key when encryption or signing is used, a config section that is
 * not an array or has an unsupported key, a hashing cost that is not an
 * integer or is out of range, or argon2id chosen on a PHP build without it.
 * A payload that cannot be decrypted or verified, or a password that does
 * not match, is not an error of this kind; Encryptor::decrypt(),
 * Signer::verify() and PasswordHasher::verify() return false for those.
 */
final class InvalidConfigException extends LogicException
{
}
