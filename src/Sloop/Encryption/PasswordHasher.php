<?php

declare(strict_types=1);

namespace Sloop\Encryption;

use Sloop\Encryption\Exception\InvalidConfigException;

/**
 * Hashes and verifies passwords with bcrypt (the default) or argon2id.
 *
 * Bcrypt reads only the first 72 bytes of a password and stops at a NUL byte,
 * so a password longer than 72 bytes or containing NUL is refused instead of
 * being shortened: hash() returns false when bcrypt is configured, and
 * verify() returns false when the stored hash is a bcrypt hash. Argon2id has
 * neither limit.
 *
 * verify() accepts bcrypt ($2a$, $2b$, $2y$), argon2i and argon2id hashes,
 * whichever of them the current settings write, so stored hashes keep
 * working after the algorithm or the costs change; needsRehash() then tells
 * when to hash the password again with the current settings, typically right
 * after a successful login. Older crypt() formats (DES, MD5, SHA-256 and
 * SHA-512 crypt, and $2x$) are refused: they shorten the password or cut it
 * at NUL, and SHA crypt's cost grows faster than the password's length.
 */
final readonly class PasswordHasher
{
    /**
     * Longest password bcrypt reads, in bytes.
     */
    private const int BCRYPT_MAX_BYTES = 72;

    /**
     * Smallest bcrypt cost PHP accepts.
     */
    private const int BCRYPT_MIN_COST = 4;

    /**
     * Largest bcrypt cost PHP accepts.
     */
    private const int BCRYPT_MAX_COST = 31;

    /**
     * Memory argon2id needs per thread, in KiB.
     */
    private const int ARGON2_MIN_MEMORY_PER_THREAD = 8;

    /**
     * Largest argon2id thread count libargon2 accepts.
     */
    private const int ARGON2_MAX_THREADS = 16_777_215;

    /**
     * Largest argon2id memory cost (KiB) and time cost libargon2 accepts on a 64-bit build.
     */
    private const int ARGON2_MAX_COST = 4_294_967_295;

    /**
     * Create a hasher.
     *
     * The defaults are PHP's own. The argon2id settings are checked whichever
     * algorithm is chosen.
     *
     * @param  PasswordAlgorithm      $algorithm     Algorithm used by hash() for new hashes
     * @param  int                    $bcryptCost    Bcrypt cost, 4 to 31; each step doubles the time
     * @param  int                    $argon2Memory  Argon2id memory cost in KiB, at least 8 per thread and at most 4294967295
     * @param  int                    $argon2Time    Argon2id time cost (number of passes), 1 to 4294967295
     * @param  int                    $argon2Threads Argon2id thread count, 1 to 16777215
     * @throws InvalidConfigException If a cost is out of range, or argon2id is chosen and this PHP build lacks it
     */
    public function __construct(
        public PasswordAlgorithm $algorithm = PasswordAlgorithm::Bcrypt,
        public int $bcryptCost = 12,
        public int $argon2Memory = 65536,
        public int $argon2Time = 4,
        public int $argon2Threads = 1,
    ) {
        if ($bcryptCost < self::BCRYPT_MIN_COST || $bcryptCost > self::BCRYPT_MAX_COST) {
            throw new InvalidConfigException('The bcrypt cost must be between ' . self::BCRYPT_MIN_COST . ' and ' . self::BCRYPT_MAX_COST . ', got ' . $bcryptCost . '.');
        }

        if ($argon2Time < 1 || $argon2Time > self::ARGON2_MAX_COST) {
            throw new InvalidConfigException('The argon2id time cost must be between 1 and ' . self::ARGON2_MAX_COST . ', got ' . $argon2Time . '.');
        }

        if ($argon2Threads < 1 || $argon2Threads > self::ARGON2_MAX_THREADS) {
            throw new InvalidConfigException('The argon2id thread count must be between 1 and ' . self::ARGON2_MAX_THREADS . ', got ' . $argon2Threads . '.');
        }

        if ($argon2Memory > self::ARGON2_MAX_COST) {
            throw new InvalidConfigException('The argon2id memory cost must be at most ' . self::ARGON2_MAX_COST . ' KiB, got ' . $argon2Memory . '.');
        }

        $minMemory = self::ARGON2_MIN_MEMORY_PER_THREAD * $argon2Threads;
        if ($argon2Memory < $minMemory) {
            throw new InvalidConfigException(
                'The argon2id memory cost must be at least ' . self::ARGON2_MIN_MEMORY_PER_THREAD . ' KiB per thread ('
                . $minMemory . ' for ' . $argon2Threads . ' thread' . ($argon2Threads === 1 ? '' : 's') . '), got ' . $argon2Memory . '.',
            );
        }

        if (!$algorithm->isAvailable()) {
            throw new InvalidConfigException('This PHP build cannot hash with ' . $algorithm->value . '. Choose another hashing.algorithm, or use a PHP built with argon2 support.');
        }
    }

    /**
     * Hash a password with the configured algorithm and costs.
     *
     * @param  string       $password Password; any bytes, including the empty string
     * @return string|false The hash, or false when bcrypt is configured and the password is longer than 72 bytes or contains NUL
     */
    public function hash(#[\SensitiveParameter] string $password): string|false
    {
        if ($this->algorithm === PasswordAlgorithm::Bcrypt && !self::fitsBcrypt($password)) {
            return false;
        }

        return password_hash($password, $this->algorithm->phpAlgorithm(), $this->options());
    }

    /**
     * Check a password against a stored bcrypt, argon2i or argon2id hash.
     *
     * @param  string $password Password to check
     * @param  string $hash     Stored hash
     * @return bool   False when the password does not match, the hash is malformed or in another format, or the hash is a bcrypt hash and the password is longer than 72 bytes or contains NUL
     */
    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        if (self::isBcryptHash($hash)) {
            if (!self::fitsBcrypt($password)) {
                return false;
            }
        } elseif (!\in_array(password_get_info($hash)['algo'], ['argon2i', 'argon2id'], true)) {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Whether a stored hash was made with another algorithm or other costs than the current ones.
     *
     * A hash in no format PHP recognizes also needs rehashing.
     *
     * @param  string $hash Stored hash
     * @return bool
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm->phpAlgorithm(), $this->options());
    }

    /**
     * Options passed to password_hash() and password_needs_rehash() for the configured algorithm.
     *
     * @return array<string, int>
     */
    private function options(): array
    {
        return match ($this->algorithm) {
            PasswordAlgorithm::Bcrypt   => ['cost' => $this->bcryptCost],
            PasswordAlgorithm::Argon2id => ['memory_cost' => $this->argon2Memory, 'time_cost' => $this->argon2Time, 'threads' => $this->argon2Threads],
        };
    }

    /**
     * Whether a hash is in one of the bcrypt formats verify() accepts.
     *
     * $2x$ (crypt_blowfish's mode reproducing its sign-extension bug) is left out.
     *
     * @param  string $hash Stored hash
     * @return bool
     */
    private static function isBcryptHash(string $hash): bool
    {
        return str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$') || str_starts_with($hash, '$2y$');
    }

    /**
     * Whether bcrypt reads the whole password: at most 72 bytes and no NUL byte.
     *
     * @param  string $password Password
     * @return bool
     */
    private static function fitsBcrypt(#[\SensitiveParameter] string $password): bool
    {
        return \strlen($password) <= self::BCRYPT_MAX_BYTES && !str_contains($password, "\0");
    }
}
