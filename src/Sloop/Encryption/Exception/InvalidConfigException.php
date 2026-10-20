<?php

declare(strict_types=1);

namespace Sloop\Encryption\Exception;

use LogicException;

/**
 * Thrown when the encryption, signing, hashing or security config is missing or malformed.
 *
 * Indicates a programmer error in config files: a key that is not 64
 * hexadecimal characters, an unknown cipher or hashing algorithm name, a
 * missing key when encryption or signing is used, a config section that is
 * not an array or has an unsupported key, a hashing cost that is not an
 * integer or is out of range, argon2id chosen on a PHP build without it, or
 * a security.headers entry that is malformed (a blank or non-string value,
 * a value with a line break, an integer key, a name given twice in different
 * case, or a name or value that cannot be sent), or a security.csrf entry
 * that is malformed (an origin that is not a string or null, is not
 * scheme://host[:port] as browsers send it, names the default port, or for
 * the application's own origin uses a scheme other than http or https, or
 * trusted_origins that is not a list of strings).
 * A payload that cannot be decrypted or verified, or a password that does
 * not match, is not an error of this kind; Encryptor::decrypt(),
 * Signer::verify() and PasswordHasher::verify() return false for those.
 */
final class InvalidConfigException extends LogicException
{
}
