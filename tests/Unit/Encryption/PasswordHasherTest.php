<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Encryption;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Encryption\Exception\InvalidConfigException;
use Sloop\Encryption\PasswordAlgorithm;
use Sloop\Encryption\PasswordHasher;

final class PasswordHasherTest extends TestCase
{
    private static function bcrypt(): PasswordHasher
    {
        return new PasswordHasher(PasswordAlgorithm::Bcrypt, bcryptCost: 4);
    }

    private static function argon2id(): PasswordHasher
    {
        return new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: 8, argon2Time: 1, argon2Threads: 1);
    }

    // -------------------------------------------------------
    // hash / verify
    // -------------------------------------------------------

    /**
     * @return array<string, array{PasswordHasher, string}>
     */
    public static function hasherAndPasswordProvider(): array
    {
        $cases = [];
        foreach (['bcrypt' => self::bcrypt(), 'argon2id' => self::argon2id()] as $name => $hasher) {
            $cases[$name . ' ascii']     = [$hasher, 'correct horse battery staple'];
            $cases[$name . ' multibyte'] = [$hasher, 'パスワード🔑'];
            $cases[$name . ' empty']     = [$hasher, ''];
            $cases[$name . ' 72 bytes']  = [$hasher, str_repeat('あ', 24)];
        }

        return $cases;
    }

    #[DataProvider('hasherAndPasswordProvider')]
    public function testVerifyAcceptsThePasswordThatWasHashed(PasswordHasher $hasher, string $password): void
    {
        $hash = $hasher->hash($password);

        $this->assertIsString($hash);
        $this->assertTrue($hasher->verify($password, $hash));
    }

    #[DataProvider('hasherAndPasswordProvider')]
    public function testVerifyRejectsAnotherPasswordOfTheSameLength(PasswordHasher $hasher, string $password): void
    {
        // Same length (except for the empty password, compared with 'x'), last byte changed: bcrypt's 72-byte guard does not decide this, the hash does.
        $other = $password === '' ? 'x' : substr($password, 0, -1) . \chr(\ord($password[-1]) ^ 0x01);
        $hash  = $hasher->hash($password);

        $this->assertIsString($hash);
        $this->assertSame($password === '' ? 1 : \strlen($password), \strlen($other));
        $this->assertTrue($hasher->verify($password, $hash));
        $this->assertFalse($hasher->verify($other, $hash));
    }

    public function testHashWithBcryptWritesABcryptHashWithTheConfiguredCost(): void
    {
        $hash = self::bcrypt()->hash('secret');

        $this->assertIsString($hash);
        $this->assertSame(['algo' => '2y', 'algoName' => 'bcrypt', 'options' => ['cost' => 4]], password_get_info($hash));
    }

    public function testHashWithArgon2idWritesAnArgon2idHashWithTheConfiguredParameters(): void
    {
        $hash = new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: 16, argon2Time: 2, argon2Threads: 2)->hash('secret');

        $this->assertIsString($hash);
        $this->assertSame(
            ['algo' => 'argon2id', 'algoName' => 'argon2id', 'options' => ['memory_cost' => 16, 'time_cost' => 2, 'threads' => 2]],
            password_get_info($hash),
        );
    }

    public function testConstructorDefaultsToPhpsCosts(): void
    {
        $hasher = new PasswordHasher();

        $this->assertSame(PasswordAlgorithm::Bcrypt, $hasher->algorithm);
        $this->assertSame(12, $hasher->bcryptCost);
        $this->assertSame(65536, $hasher->argon2Memory);
        $this->assertSame(4, $hasher->argon2Time);
        $this->assertSame(1, $hasher->argon2Threads);
    }

    public function testHashGivesADifferentHashEachTime(): void
    {
        $this->assertNotSame(self::bcrypt()->hash('same'), self::bcrypt()->hash('same'));
    }

    // -------------------------------------------------------
    // inputs bcrypt cannot represent
    // -------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function unrepresentableByBcryptProvider(): array
    {
        return [
            '73 bytes'                  => [str_repeat('a', 73)],
            '72 bytes plus a character' => [str_repeat('あ', 24) . 'い'],
            'NUL in the middle'         => ["ab\0cd"],
            'NUL at the end'            => ["ab\0"],
            'NUL only'                  => ["\0"],
        ];
    }

    #[DataProvider('unrepresentableByBcryptProvider')]
    public function testHashWithBcryptReturnsFalseForAPasswordBcryptCannotRepresent(string $password): void
    {
        $this->assertFalse(self::bcrypt()->hash($password));
    }

    #[DataProvider('unrepresentableByBcryptProvider')]
    public function testHashWithArgon2idAcceptsAPasswordBcryptCannotRepresent(string $password): void
    {
        $hash = self::argon2id()->hash($password);

        $this->assertIsString($hash);
        $this->assertTrue(self::argon2id()->verify($password, $hash));
    }

    public function testVerifyAgainstABcryptHashRejectsAPasswordLongerThan72Bytes(): void
    {
        $password = str_repeat('a', 72);
        $hash     = self::bcrypt()->hash($password);
        $this->assertIsString($hash);

        $this->assertTrue(password_verify($password . 'b', $hash), 'PHP itself ignores bytes after the 72nd');
        $this->assertFalse(self::bcrypt()->verify($password . 'b', $hash));
    }

    public function testVerifyAgainstABcryptHashRejectsAPasswordContainingNul(): void
    {
        $hash = self::bcrypt()->hash('ab');
        $this->assertIsString($hash);

        $this->assertTrue(password_verify("ab\0cd", $hash), 'PHP itself stops reading at NUL');
        $this->assertFalse(self::bcrypt()->verify("ab\0cd", $hash));
    }

    public function testVerifyRejectsUnrepresentablePasswordsForEveryBcryptSpelling(): void
    {
        $hash = password_hash('ab', PASSWORD_BCRYPT, ['cost' => 4]);

        foreach (['$2y$', '$2a$', '$2b$'] as $spelling) {
            $variant = $spelling . substr($hash, 4);
            $this->assertTrue(password_verify('ab', $variant), $spelling);
            $this->assertTrue(self::argon2id()->verify('ab', $variant), $spelling);
            $this->assertFalse(self::argon2id()->verify("ab\0cd", $variant), $spelling);
        }
    }

    public function testArgon2idHashesAndVerifiesAPasswordOfOneMebibyte(): void
    {
        $password = str_repeat('long password ', 74899);
        $hash     = self::argon2id()->hash($password);
        $this->assertIsString($hash);

        $this->assertGreaterThan(1024 * 1024, \strlen($password));
        $this->assertTrue(self::argon2id()->verify($password, $hash));
        $this->assertFalse(self::argon2id()->verify($password . 'x', $hash));
    }

    public function testVerifyAgainstAnArgon2idHashAcceptsAPasswordBcryptCannotRepresent(): void
    {
        $password = str_repeat('a', 100) . "\0" . 'tail';
        $hash     = self::argon2id()->hash($password);
        $this->assertIsString($hash);

        $this->assertTrue(self::bcrypt()->verify($password, $hash));
        $this->assertFalse(self::bcrypt()->verify(str_repeat('a', 100), $hash));
    }

    // -------------------------------------------------------
    // hashes made with another algorithm
    // -------------------------------------------------------

    public function testVerifyAcceptsAHashMadeWithAnotherAlgorithm(): void
    {
        $argon   = self::argon2id()->hash('secret');
        $bcrypt  = self::bcrypt()->hash('secret');
        $argon2i = password_hash('secret', PASSWORD_ARGON2I, ['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1]);
        $this->assertIsString($argon);
        $this->assertIsString($bcrypt);

        $this->assertTrue(self::bcrypt()->verify('secret', $argon));
        $this->assertTrue(self::bcrypt()->verify('secret', $argon2i));
        $this->assertTrue(self::argon2id()->verify('secret', $bcrypt));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenHashProvider(): array
    {
        $bcrypt = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);

        return [
            'empty'            => [''],
            'not a hash'       => ['not-a-hash'],
            'md5'              => [md5('secret')],
            'truncated bcrypt' => [substr($bcrypt, 0, 30)],
            'bcrypt prefix'    => ['$2y$'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legacyCryptHashProvider(): array
    {
        $bcrypt = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);

        return [
            'DES'           => [crypt('secret', 'ab')],
            'extended DES'  => [crypt('secret', '_J9..salt')],
            'MD5 crypt'     => [crypt('secret', '$1$saltsalt$')],
            'SHA-256 crypt' => [crypt('secret', '$5$saltsalt$')],
            'SHA-512 crypt' => [crypt('secret', '$6$saltsalt$')],
            'bcrypt $2x$'   => ['$2x$' . substr($bcrypt, 4)],
        ];
    }

    #[DataProvider('legacyCryptHashProvider')]
    public function testVerifyReturnsFalseForAnOlderCryptFormatEvenWithTheRightPassword(string $hash): void
    {
        $this->assertTrue(password_verify('secret', $hash), 'PHP itself accepts this format');
        $this->assertFalse(self::bcrypt()->verify('secret', $hash));
        $this->assertFalse(self::argon2id()->verify('secret', $hash));
    }

    #[DataProvider('legacyCryptHashProvider')]
    public function testNeedsRehashIsTrueForAnOlderCryptFormat(string $hash): void
    {
        $this->assertTrue(self::bcrypt()->needsRehash($hash));
    }

    #[DataProvider('brokenHashProvider')]
    public function testVerifyReturnsFalseForABrokenHash(string $hash): void
    {
        $this->assertFalse(self::bcrypt()->verify('secret', $hash));
        $this->assertFalse(self::argon2id()->verify('secret', $hash));
    }

    // -------------------------------------------------------
    // needsRehash
    // -------------------------------------------------------

    public function testNeedsRehashIsFalseForAHashMadeWithTheSameSettings(): void
    {
        $bcrypt = self::bcrypt()->hash('secret');
        $argon  = self::argon2id()->hash('secret');
        $this->assertIsString($bcrypt);
        $this->assertIsString($argon);

        $this->assertFalse(self::bcrypt()->needsRehash($bcrypt));
        $this->assertFalse(self::argon2id()->needsRehash($argon));
    }

    public function testNeedsRehashIsTrueForAnotherAlgorithm(): void
    {
        $bcrypt = self::bcrypt()->hash('secret');
        $argon  = self::argon2id()->hash('secret');
        $this->assertIsString($bcrypt);
        $this->assertIsString($argon);

        $this->assertTrue(self::bcrypt()->needsRehash($argon));
        $this->assertTrue(self::argon2id()->needsRehash($bcrypt));
    }

    public function testNeedsRehashIsTrueForOtherCosts(): void
    {
        $bcrypt = self::bcrypt()->hash('secret');
        $argon  = self::argon2id()->hash('secret');
        $this->assertIsString($bcrypt);
        $this->assertIsString($argon);

        $this->assertTrue(new PasswordHasher(PasswordAlgorithm::Bcrypt, bcryptCost: 5)->needsRehash($bcrypt));
        $this->assertTrue(new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: 16, argon2Time: 1, argon2Threads: 1)->needsRehash($argon));
        $this->assertTrue(new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: 8, argon2Time: 2, argon2Threads: 1)->needsRehash($argon));
        $this->assertTrue(new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: 16, argon2Time: 1, argon2Threads: 2)->needsRehash($argon));
    }

    #[DataProvider('brokenHashProvider')]
    public function testNeedsRehashIsTrueForABrokenHash(string $hash): void
    {
        $this->assertTrue(self::bcrypt()->needsRehash($hash));
    }

    // -------------------------------------------------------
    // settings
    // -------------------------------------------------------

    /**
     * @return array<string, array{int}>
     */
    public static function invalidBcryptCostProvider(): array
    {
        return ['cost 3' => [3], 'cost 32' => [32], 'cost 0' => [0], 'cost -1' => [-1]];
    }

    #[DataProvider('invalidBcryptCostProvider')]
    public function testConstructorRejectsABcryptCostOutsideFourToThirtyOne(int $cost): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains('The bcrypt cost must be between 4 and 31, got ' . $cost . '.');

        new PasswordHasher(PasswordAlgorithm::Bcrypt, bcryptCost: $cost);
    }

    public function testConstructorAcceptsTheBcryptCostBounds(): void
    {
        $this->assertSame(4, new PasswordHasher(bcryptCost: 4)->bcryptCost);
        $this->assertSame(31, new PasswordHasher(bcryptCost: 31)->bcryptCost);
    }

    /**
     * @return array<string, array{int, int, int, string}>
     */
    public static function invalidArgon2SettingsProvider(): array
    {
        return [
            'time 0'                    => [8, 0, 1, 'The argon2id time cost must be between 1 and 4294967295, got 0.'],
            'time above the maximum'    => [8, 4294967296, 1, 'The argon2id time cost must be between 1 and 4294967295, got 4294967296.'],
            'memory above the maximum'  => [4294967296, 1, 1, 'The argon2id memory cost must be at most 4294967295 KiB, got 4294967296.'],
            'threads 0'                 => [8, 1, 0, 'The argon2id thread count must be between 1 and 16777215, got 0.'],
            'threads above the maximum' => [1024, 1, 16777216, 'The argon2id thread count must be between 1 and 16777215, got 16777216.'],
            'memory 7'                  => [7, 1, 1, 'The argon2id memory cost must be at least 8 KiB per thread (8 for 1 thread), got 7.'],
            'memory below 8 per thread' => [15, 1, 2, 'The argon2id memory cost must be at least 8 KiB per thread (16 for 2 threads), got 15.'],
        ];
    }

    #[DataProvider('invalidArgon2SettingsProvider')]
    public function testConstructorRejectsArgon2SettingsPhpRejects(int $memory, int $time, int $threads, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: $memory, argon2Time: $time, argon2Threads: $threads);
    }

    public function testConstructorChecksArgon2SettingsEvenWhenBcryptIsChosen(): void
    {
        $this->expectException(InvalidConfigException::class);

        new PasswordHasher(PasswordAlgorithm::Bcrypt, argon2Time: 0);
    }

    public function testConstructorAcceptsTheArgon2Bounds(): void
    {
        $hasher = new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: 16, argon2Time: 1, argon2Threads: 2);

        $this->assertIsString($hasher->hash('x'));

        // The largest values are accepted; hashing with them would need terabytes, so only construct them.
        $this->assertSame(4294967295, new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: 4294967295)->argon2Memory);
        $this->assertSame(4294967295, new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Time: 4294967295)->argon2Time);
        $this->assertSame(16777215, new PasswordHasher(PasswordAlgorithm::Argon2id, argon2Memory: 8 * 16777215, argon2Threads: 16777215)->argon2Threads);
    }
}
