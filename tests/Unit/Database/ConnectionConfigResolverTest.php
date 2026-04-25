<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use PDO;
use Pdo\Mysql as PdoMysql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\ConnectionConfigResolver;
use Sloop\Database\Exception\InvalidConfigException;

final class ConnectionConfigResolverTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function validConfigProvider(): array
    {
        return [
            'minimal required keys' => [[
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
            ]],
            'all supported keys' => [[
                'driver'                  => 'mysql',
                'host'                    => '127.0.0.1',
                'port'                    => 3306,
                'database'                => 'app',
                'username'                => 'root',
                'password'                => 'secret',
                'charset'                 => 'utf8mb4',
                'collation'               => 'utf8mb4_unicode_ci',
                'connect_timeout_seconds' => 5,
                'options'                 => [PDO::ATTR_PERSISTENT => false],
            ]],
            'nullable username and password' => [[
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                'username' => null,
                'password' => null,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('validConfigProvider')]
    public function testValidateAcceptsValidConfig(array $config): void
    {
        ConnectionConfigResolver::validate('master', $config);

        // No exception is success
        $this->expectNotToPerformAssertions();
    }

    public function testValidateRejectsUnknownKey(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'           => 'mysql',
                'host'             => 'localhost',
                'database'         => 'app',
                'query_timeout_ms' => 5000,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: unsupported config key "query_timeout_ms".',
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsMistypedKey(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'  => 'mysql',
                'host'    => 'localhost',
                'databse' => 'app',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: unsupported config key "databse".',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredKeyProvider(): array
    {
        return [
            'driver missing'   => ['driver'],
            'host missing'     => ['host'],
            'database missing' => ['database'],
        ];
    }

    #[DataProvider('requiredKeyProvider')]
    public function testValidateRejectsMissingRequiredKey(string $missingKey): void
    {
        $config = [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => 'app',
        ];
        unset($config[$missingKey]);

        try {
            ConnectionConfigResolver::validate('master', $config);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: missing required config key "' . $missingKey . '".',
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsUnsupportedDriver(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'pgsql',
                'host'     => 'localhost',
                'database' => 'app',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                "Connection [master]: unsupported driver \"pgsql\". Only 'mysql' is supported.",
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsNonStringDriver(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 1,
                'host'     => 'localhost',
                'database' => 'app',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: config key "driver" must be a string.',
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsNonIntPort(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                'port'     => '3306',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: config key "port" must be an integer.',
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsNonStringNullableUsername(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                'username' => 123,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: config key "username" must be a string or null.',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'charset with space' => [
                'charset', 'utf8 mb4',
                'Connection [master]: config key "charset" must contain only alphanumeric and underscore characters, got "utf8 mb4".',
            ],
            'charset with semicolon' => [
                'charset', 'utf8mb4;DROP',
                'Connection [master]: config key "charset" must contain only alphanumeric and underscore characters, got "utf8mb4;DROP".',
            ],
            'collation with hyphen' => [
                'collation', 'utf8mb4-unicode',
                'Connection [master]: config key "collation" must contain only alphanumeric and underscore characters, got "utf8mb4-unicode".',
            ],
            'charset non-string' => [
                'charset', 123,
                'Connection [master]: config key "charset" must be a string.',
            ],
        ];
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function testValidateRejectsInvalidIdentifier(string $key, mixed $value, string $expectedMessage): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                $key       => $value,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }
    }

    public function testValidateRejectsNonArrayOptions(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                'options'  => 'invalid',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: config key "options" must be an array.',
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsStringKeyedOptions(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                'options'  => ['attr_persistent' => false],
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: config key "options" must be an array with integer (PDO::ATTR_*) keys.',
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsIntegerKey(): void
    {
        // Application::filterStringKeys removes int keys before the config
        // reaches the resolver, so this scenario only fires when the resolver
        // is called directly. Cover the defensive `is_string($key)` branch.
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app',
                0          => 'unexpected_int_key',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: unsupported config key "0".',
                $e->getMessage(),
            );
        }
    }

    public function testValidateIncludesConnectionNameInMessage(): void
    {
        // Confirms the connection name is propagated into all error messages.
        // Asserts the full message so concat-mutation cannot escape.
        try {
            ConnectionConfigResolver::validate('analytics', [
                'driver' => 'mysql',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [analytics]: missing required config key "host".',
                $e->getMessage(),
            );
        }
    }

    public function testValidateReturnsValidatedConfigWithValues(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'                  => 'mysql',
            'host'                    => '127.0.0.1',
            'port'                    => 3306,
            'database'                => 'app',
            'username'                => 'root',
            'password'                => 'secret',
            'charset'                 => 'utf8mb4',
            'collation'               => 'utf8mb4_unicode_ci',
            'connect_timeout_seconds' => 5,
            'options'                 => [PDO::ATTR_PERSISTENT => false],
        ]);

        // ValidatedConfig type is statically guaranteed by validate()'s return type.
        // Assert the field values to verify each config key is correctly extracted.
        $this->assertSame('mysql', $validated->driver);
        $this->assertSame('127.0.0.1', $validated->host);
        $this->assertSame(3306, $validated->port);
        $this->assertSame('app', $validated->database);
        $this->assertSame('root', $validated->username);
        $this->assertSame('secret', $validated->password);
        $this->assertSame('utf8mb4', $validated->charset);
        $this->assertSame('utf8mb4_unicode_ci', $validated->collation);
        $this->assertSame(5, $validated->connectTimeoutSeconds);
        $this->assertSame([PDO::ATTR_PERSISTENT => false], $validated->options);
    }

    public function testValidateReturnsValidatedConfigWithMinimalInput(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => 'app',
        ]);

        // Nullable fields default to null when the corresponding raw key is omitted.
        $this->assertSame('mysql', $validated->driver);
        $this->assertSame('localhost', $validated->host);
        $this->assertSame('app', $validated->database);
        $this->assertNull($validated->port);
        $this->assertNull($validated->username);
        $this->assertNull($validated->password);
        $this->assertNull($validated->charset);
        $this->assertNull($validated->collation);
        $this->assertNull($validated->connectTimeoutSeconds);
        $this->assertSame([], $validated->options);
    }

    public function testResolveDsnBuildsMinimalDsn(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'   => 'mysql',
            'host'     => 'db.example.com',
            'database' => 'app',
        ]);

        $this->assertSame('mysql:host=db.example.com;dbname=app', ConnectionConfigResolver::resolveDsn($validated));
    }

    public function testResolveDsnIncludesPortWhenSet(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => 3307,
            'database' => 'app',
        ]);

        $this->assertSame('mysql:host=127.0.0.1;port=3307;dbname=app', ConnectionConfigResolver::resolveDsn($validated));
    }

    public function testResolvePdoOptionsAppliesDefaults(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => 'app',
        ]);

        $options = ConnectionConfigResolver::resolvePdoOptions($validated);

        $this->assertSame(2, $options[PDO::ATTR_TIMEOUT]);
        $this->assertSame('SET NAMES utf8mb4', $options[PdoMysql::ATTR_INIT_COMMAND]);
    }

    public function testResolvePdoOptionsAppliesConnectTimeout(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'                  => 'mysql',
            'host'                    => 'localhost',
            'database'                => 'app',
            'connect_timeout_seconds' => 10,
        ]);

        $options = ConnectionConfigResolver::resolvePdoOptions($validated);

        $this->assertSame(10, $options[PDO::ATTR_TIMEOUT]);
    }

    public function testResolvePdoOptionsAppliesCharsetWithoutCollation(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => 'app',
            'charset'  => 'latin1',
        ]);

        $options = ConnectionConfigResolver::resolvePdoOptions($validated);

        $this->assertSame('SET NAMES latin1', $options[PdoMysql::ATTR_INIT_COMMAND]);
    }

    public function testResolvePdoOptionsAppliesCharsetAndCollation(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'    => 'mysql',
            'host'      => 'localhost',
            'database'  => 'app',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $options = ConnectionConfigResolver::resolvePdoOptions($validated);

        $this->assertSame('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci', $options[PdoMysql::ATTR_INIT_COMMAND]);
    }

    public function testResolvePdoOptionsAppliesCollationWithDefaultCharset(): void
    {
        // charset omitted; default 'utf8mb4' is combined with the explicit collation.
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'    => 'mysql',
            'host'      => 'localhost',
            'database'  => 'app',
            'collation' => 'utf8mb4_general_ci',
        ]);

        $options = ConnectionConfigResolver::resolvePdoOptions($validated);

        $this->assertSame('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci', $options[PdoMysql::ATTR_INIT_COMMAND]);
    }

    public function testResolvePdoOptionsMergesUserOptions(): void
    {
        // Multiple user options ensures the resolver returns all entries
        // (kills ArrayOneItem mutation that would slice to a single element).
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => 'app',
            'options'  => [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_AUTOCOMMIT => false,
            ],
        ]);

        $options = ConnectionConfigResolver::resolvePdoOptions($validated);

        $this->assertSame(true, $options[PDO::ATTR_PERSISTENT]);
        $this->assertSame(false, $options[PDO::ATTR_AUTOCOMMIT]);
        $this->assertSame(2, $options[PDO::ATTR_TIMEOUT]);
        $this->assertSame('SET NAMES utf8mb4', $options[PdoMysql::ATTR_INIT_COMMAND]);
    }

    public function testResolvePdoOptionsAllowsUserOverridesOfResolverDefaults(): void
    {
        $validated = ConnectionConfigResolver::validate('master', [
            'driver'                  => 'mysql',
            'host'                    => 'localhost',
            'database'                => 'app',
            'connect_timeout_seconds' => 5,
            'options'                 => [
                PDO::ATTR_TIMEOUT => 30,
            ],
        ]);

        $options = ConnectionConfigResolver::resolvePdoOptions($validated);

        $this->assertSame(30, $options[PDO::ATTR_TIMEOUT]);
    }
}
