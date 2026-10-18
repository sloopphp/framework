<?php

declare(strict_types=1);

namespace Sloop\Encryption;

/**
 * Algorithm PasswordHasher uses to write new password hashes.
 *
 * The value is the name read from the `hashing.algorithm` config entry.
 * Verification does not depend on this choice: PHP reads the algorithm from
 * each stored hash.
 */
enum PasswordAlgorithm: string
{
    case Bcrypt   = 'bcrypt';
    case Argon2id = 'argon2id';

    /**
     * The PHP algorithm identifier passed to password_hash() and password_needs_rehash().
     *
     * The string is written out instead of using PASSWORD_ARGON2ID, which PHP
     * does not define when it is built without argon2.
     *
     * @return non-empty-string
     */
    public function phpAlgorithm(): string
    {
        return match ($this) {
            self::Bcrypt   => '2y',
            self::Argon2id => 'argon2id',
        };
    }

    /**
     * Whether this PHP build can hash with this algorithm.
     *
     * Bcrypt is always built in; argon2id needs PHP built with libargon2 or libsodium.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return \in_array($this->phpAlgorithm(), password_algos(), true);
    }
}
