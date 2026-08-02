<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database\Config;

use PDO;
use Pdo\Mysql as PdoMysql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Config\ConnectionConfigResolver;
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

    public function testValidateRejectsPersistent(): void
    {
        // `persistent` is a pool-only key and is not in ALLOWED_KEYS. The
        // pool-only contract must hold even when validate() is invoked directly
        // on a single-connection config. testValidateRejectsUnknownKey already
        // covers the generic unknown-key path with query_timeout_ms; this
        // dedicated case mirrors testValidatePoolRejectsPersistentInsideReplica
        // so the pool-only invariant is asserted on both validate() and
        // validatePool() entry points.
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'     => 'mysql',
                'host'       => 'localhost',
                'database'   => 'app',
                'persistent' => true,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: unsupported config key "persistent".',
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

    public function testValidateRejectsNonPositiveConnectTimeout(): void
    {
        // A zero or negative PDO::ATTR_TIMEOUT makes replica failover timing
        // nondeterministic, so the resolver requires >= 1 like the other
        // duration keys.
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'                  => 'mysql',
                'host'                    => 'localhost',
                'database'                => 'app',
                'connect_timeout_seconds' => 0,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: config key "connect_timeout_seconds" must be >= 1, got 0.',
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsHostContainingDsnMetacharacters(): void
    {
        // `;` and `=` are DSN metacharacters: a tainted host such as
        // `x;unix_socket=/tmp/evil.sock` could append extra DSN keys and
        // redirect the connection.
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'mysql',
                'host'     => 'x;unix_socket=/tmp/evil.sock',
                'database' => 'app',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: config key "host" must not contain ";", "=", or NUL.',
                $e->getMessage(),
            );
        }
    }

    public function testValidateRejectsDatabaseContainingDsnMetacharacters(): void
    {
        try {
            ConnectionConfigResolver::validate('master', [
                'driver'   => 'mysql',
                'host'     => 'localhost',
                'database' => 'app=x',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [master]: config key "database" must not contain ";", "=", or NUL.',
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

        $this->assertTrue($options[PDO::ATTR_PERSISTENT]);
        $this->assertFalse($options[PDO::ATTR_AUTOCOMMIT]);
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

    public function testValidatePoolReturnsPrimaryOnlyPoolWhenReadIsAbsent(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'   => 'mysql',
            'host'     => 'primary.example.com',
            'database' => 'app',
            'username' => 'user',
            'password' => 'pass',
        ]);

        $this->assertSame('mydb', $pool->name);
        $this->assertSame('primary.example.com', $pool->primary->host);
        $this->assertSame('user', $pool->primary->username);
        $this->assertSame([], $pool->replicas);
        $this->assertTrue($pool->healthCheck);
        $this->assertSame(300, $pool->deadCacheTtlSeconds);
        $this->assertSame('random', $pool->replicaSelector);
        $this->assertSame(1, $pool->maxConnectionAttempts);
    }

    public function testValidatePoolInheritsPrimaryKeysIntoReplicas(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'   => 'mysql',
            'host'     => 'primary.example.com',
            'database' => 'app',
            'username' => 'user',
            'password' => 'pass',
            'charset'  => 'utf8mb4',
            'read'     => [
                ['host' => 'replica-1.example.com'],
                ['host' => 'replica-2.example.com', 'port' => 3307],
            ],
        ]);

        $this->assertCount(2, $pool->replicas);

        $replica1 = $pool->replicas[0];
        $this->assertSame('replica-1.example.com', $replica1->host);
        $this->assertNull($replica1->port);
        $this->assertSame('app', $replica1->database);
        $this->assertSame('user', $replica1->username);
        $this->assertSame('pass', $replica1->password);
        $this->assertSame('utf8mb4', $replica1->charset);

        $replica2 = $pool->replicas[1];
        $this->assertSame('replica-2.example.com', $replica2->host);
        $this->assertSame(3307, $replica2->port);
        $this->assertSame('app', $replica2->database);
    }

    public function testValidatePoolAcceptsAllPoolBehaviorKeys(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'                  => 'mysql',
            'host'                    => 'primary.example.com',
            'database'                => 'app',
            'read'                    => [
                ['host' => 'replica-1.example.com'],
                ['host' => 'replica-2.example.com'],
            ],
            'health_check'            => false,
            'dead_cache_ttl_seconds'  => 60,
            'replica_selector'        => 'random',
            'max_connection_attempts' => 6,
            'query_timeout_ms'        => 5000,
        ]);

        $this->assertFalse($pool->healthCheck);
        $this->assertSame(60, $pool->deadCacheTtlSeconds);
        $this->assertSame('random', $pool->replicaSelector);
        $this->assertSame(6, $pool->maxConnectionAttempts);
        $this->assertSame(5000, $pool->queryTimeoutMs);
    }

    public function testValidatePoolDefaultsMaxConnectionAttemptsToReplicaCountPlusOne(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'   => 'mysql',
            'host'     => 'primary.example.com',
            'database' => 'app',
            'read'     => [
                ['host' => 'replica-1.example.com'],
                ['host' => 'replica-2.example.com'],
                ['host' => 'replica-3.example.com'],
            ],
        ]);

        $this->assertSame(4, $pool->maxConnectionAttempts);
    }

    public function testValidatePoolRejectsUnknownPoolKey(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'foo'      => true,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: unsupported config key "foo".',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsNonArrayRead(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'read'     => 'not-an-array',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "read" must be an array.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsStringKeyInRead(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'read'     => [
                    'first' => ['host' => 'replica-1.example.com'],
                ],
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: "read" must be a list with integer keys, got string key "first".',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsNonArrayReplicaEntry(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'read'     => ['not-an-array'],
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: "read[0]" must be an array.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsPoolOnlyKeyInsideReplica(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica-1.example.com', 'health_check' => false],
                ],
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: "read[0]" has unsupported key "health_check". Pool-level keys must be set on the pool itself, not inside read[].',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolPropagatesReplicaValidationErrorWithLocation(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica-1.example.com'],
                    ['port' => 'not-an-int'],
                ],
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb.read[1]]: config key "port" must be an integer.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsNonBooleanHealthCheck(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'       => 'mysql',
                'host'         => 'primary.example.com',
                'database'     => 'app',
                'health_check' => 'yes',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "health_check" must be a boolean.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsNonIntDeadCacheTtl(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'                 => 'mysql',
                'host'                   => 'primary.example.com',
                'database'               => 'app',
                'dead_cache_ttl_seconds' => '300',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "dead_cache_ttl_seconds" must be an integer.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsZeroDeadCacheTtl(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'                 => 'mysql',
                'host'                   => 'primary.example.com',
                'database'               => 'app',
                'dead_cache_ttl_seconds' => 0,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "dead_cache_ttl_seconds" must be >= 1, got 0.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolAcceptsAnyReplicaSelectorIdentifier(): void
    {
        // Which identifiers are valid is determined by what ReplicaSelectorRegistry
        // registers, so the value passes through here. Unregistered identifiers are
        // rejected by ConnectionManager.
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'           => 'mysql',
            'host'             => 'primary.example.com',
            'database'         => 'app',
            'replica_selector' => 'round_robin',
        ]);

        $this->assertSame('round_robin', $pool->replicaSelector);
    }

    public function testValidatePoolRejectsNonStringReplicaSelector(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'           => 'mysql',
                'host'             => 'primary.example.com',
                'database'         => 'app',
                'replica_selector' => 1,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "replica_selector" must be a string.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsNegativeMaxConnectionAttempts(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'                  => 'mysql',
                'host'                    => 'primary.example.com',
                'database'                => 'app',
                'max_connection_attempts' => -1,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "max_connection_attempts" must be >= 1, got -1.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolAcceptsEmptyReadArray(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'   => 'mysql',
            'host'     => 'primary.example.com',
            'database' => 'app',
            'read'     => [],
        ]);

        $this->assertSame([], $pool->replicas);
        $this->assertSame(1, $pool->maxConnectionAttempts);
    }

    public function testValidatePoolPropagatesPrimaryValidationError(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver' => 'mysql',
                'host'   => 'primary.example.com',
                // database is intentionally missing to surface the primary validation path
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: missing required config key "database".',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsIntegerKeyAtPoolLevel(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                0          => 'numeric_key_value',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: unsupported config key "0".',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsIntegerKeyInsideReplica(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica-1.example.com', 0 => 'numeric_key_value'],
                ],
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: "read[0]" has unsupported key "0". Pool-level keys must be set on the pool itself, not inside read[].',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolDefaultsLoggingKeysWhenOmitted(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'   => 'mysql',
            'host'     => 'primary.example.com',
            'database' => 'app',
        ]);

        $this->assertTrue($pool->logBindings);
        $this->assertFalse($pool->logAllQueries);
        $this->assertNull($pool->slowQueryThresholdMs);
        $this->assertNull($pool->queryTimeoutMs);
    }

    public function testValidatePoolAcceptsExplicitLogBindings(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'       => 'mysql',
            'host'         => 'primary.example.com',
            'database'     => 'app',
            'log_bindings' => false,
        ]);

        $this->assertFalse($pool->logBindings);
    }

    public function testValidatePoolAcceptsExplicitLogAllQueries(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'          => 'mysql',
            'host'            => 'primary.example.com',
            'database'        => 'app',
            'log_all_queries' => true,
        ]);

        $this->assertTrue($pool->logAllQueries);
    }

    public function testValidatePoolAcceptsSlowQueryThresholdMs(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'                  => 'mysql',
            'host'                    => 'primary.example.com',
            'database'                => 'app',
            'slow_query_threshold_ms' => 500,
        ]);

        $this->assertSame(500, $pool->slowQueryThresholdMs);
    }

    public function testValidatePoolRejectsNonBooleanLogBindings(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'       => 'mysql',
                'host'         => 'primary.example.com',
                'database'     => 'app',
                'log_bindings' => 'yes',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "log_bindings" must be a boolean.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsNonBooleanLogAllQueries(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'          => 'mysql',
                'host'            => 'primary.example.com',
                'database'        => 'app',
                'log_all_queries' => 1,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "log_all_queries" must be a boolean.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsNonIntSlowQueryThresholdMs(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'                  => 'mysql',
                'host'                    => 'primary.example.com',
                'database'                => 'app',
                'slow_query_threshold_ms' => '500',
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "slow_query_threshold_ms" must be an integer.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsNonPositiveSlowQueryThresholdMs(): void
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'                  => 'mysql',
                'host'                    => 'primary.example.com',
                'database'                => 'app',
                'slow_query_threshold_ms' => 0,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "slow_query_threshold_ms" must be >= 1, got 0.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolAcceptsExplicitNullSlowQueryThresholdMs(): void
    {
        // Explicit null for slow_query_threshold_ms is the user-facing way to
        // declare "logging disabled"; it must round-trip to the stored null
        // without triggering the "must be an integer" rejection.
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'                  => 'mysql',
            'host'                    => 'primary.example.com',
            'database'                => 'app',
            'slow_query_threshold_ms' => null,
        ]);

        $this->assertNull($pool->slowQueryThresholdMs);
    }

    public function testValidatePoolAcceptsExplicitNullDeadCacheTtlSeconds(): void
    {
        // Explicit null falls through to the default TTL (300) — same helper
        // as slow_query_threshold_ms accepts null without erroring.
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'                 => 'mysql',
            'host'                   => 'primary.example.com',
            'database'               => 'app',
            'dead_cache_ttl_seconds' => null,
        ]);

        $this->assertSame(300, $pool->deadCacheTtlSeconds);
    }

    public function testValidatePoolAcceptsExplicitNullMaxConnectionAttempts(): void
    {
        // Explicit null falls through to the count(read) + 1 default; the
        // helper's null acceptance is consistent across all positive-int keys.
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'                  => 'mysql',
            'host'                    => 'primary.example.com',
            'database'                => 'app',
            'read'                    => [
                ['host' => 'replica-1.example.com'],
                ['host' => 'replica-2.example.com'],
            ],
            'max_connection_attempts' => null,
        ]);

        $this->assertSame(3, $pool->maxConnectionAttempts);
    }

    /**
     * @return array<string, array{0: int|null, 1: int|null}>
     */
    public static function validQueryTimeoutMsProvider(): array
    {
        return [
            'positive int'  => [5000, 5000],
            'minimum 1'     => [1,    1],
            'explicit null' => [null, null],
        ];
    }

    #[DataProvider('validQueryTimeoutMsProvider')]
    public function testValidatePoolAcceptsValidQueryTimeoutMs(?int $input, ?int $expected): void
    {
        // Valid inputs: positive int (typical), 1 (boundary inclusion guarding
        // a `> 1` regression), and explicit null (the "off" sentinel shared
        // with slow_query_threshold_ms / dead_cache_ttl_seconds).
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'           => 'mysql',
            'host'             => 'primary.example.com',
            'database'         => 'app',
            'query_timeout_ms' => $input,
        ]);

        $this->assertSame($expected, $pool->queryTimeoutMs);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidQueryTimeoutMsProvider(): array
    {
        return [
            'non-int string' => ['5000', 'must be an integer'],
            'float'          => [1.5,    'must be an integer'],
            'bool true'      => [true,   'must be an integer'],
            'zero'           => [0,      'must be >= 1, got 0'],
            'negative'       => [-1,     'must be >= 1, got -1'],
        ];
    }

    #[DataProvider('invalidQueryTimeoutMsProvider')]
    public function testValidatePoolRejectsInvalidQueryTimeoutMs(mixed $value, string $expectedSuffix): void
    {
        // The two error-message templates correspond to the two rejection
        // branches in extractOptionalPositiveInt: type check (`!is_int`) and
        // range check (`< 1`). Bool/float are exercised explicitly so silent
        // coercion regressions are caught.
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'           => 'mysql',
                'host'             => 'primary.example.com',
                'database'         => 'app',
                'query_timeout_ms' => $value,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "query_timeout_ms" ' . $expectedSuffix . '.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsQueryTimeoutMsInsideReplica(): void
    {
        // query_timeout_ms is pool-level: it must be set on the pool itself,
        // never inside a read[] entry. Same protection as the health_check
        // regression in testValidatePoolRejectsPoolOnlyKeyInsideReplica.
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica-1.example.com', 'query_timeout_ms' => 5000],
                ],
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: "read[0]" has unsupported key "query_timeout_ms". Pool-level keys must be set on the pool itself, not inside read[].',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolDefaultsPersistentToFalseWhenOmitted(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'   => 'mysql',
            'host'     => 'primary.example.com',
            'database' => 'app',
        ]);

        $this->assertFalse($pool->persistent);
    }

    public function testValidatePoolAcceptsExplicitPersistentTrue(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'     => 'mysql',
            'host'       => 'primary.example.com',
            'database'   => 'app',
            'persistent' => true,
        ]);

        $this->assertTrue($pool->persistent);
    }

    public function testValidatePoolAcceptsExplicitPersistentFalse(): void
    {
        $pool = ConnectionConfigResolver::validatePool('mydb', [
            'driver'     => 'mysql',
            'host'       => 'primary.example.com',
            'database'   => 'app',
            'persistent' => false,
        ]);

        $this->assertFalse($pool->persistent);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidPersistentProvider(): array
    {
        return [
            'non-bool string' => ['yes'],
            'integer 0'       => [0],
            'integer 1'       => [1],
            'float'           => [1.5],
            'null'            => [null],
            'array'           => [[]],
        ];
    }

    #[DataProvider('invalidPersistentProvider')]
    public function testValidatePoolRejectsInvalidPersistent(mixed $value): void
    {
        // extractOptionalBool rejects via !is_bool(): null / int / float /
        // string / array all fail the type check. Explicit null is treated as
        // a type error here, unlike extractOptionalPositiveInt which accepts
        // explicit null as the off-sentinel; the divergence is locked in by
        // covering null in this provider.
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'     => 'mysql',
                'host'       => 'primary.example.com',
                'database'   => 'app',
                'persistent' => $value,
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: config key "persistent" must be a boolean.',
                $e->getMessage(),
            );
        }
    }

    public function testValidatePoolRejectsPersistentInsideReplica(): void
    {
        // persistent is pool-level: it must be set on the pool itself,
        // never inside a read[] entry. Same protection as the health_check
        // regression in testValidatePoolRejectsPoolOnlyKeyInsideReplica.
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'read'     => [
                    ['host' => 'replica-1.example.com', 'persistent' => true],
                ],
            ]);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $e) {
            $this->assertSame(
                'Connection [mydb]: "read[0]" has unsupported key "persistent". Pool-level keys must be set on the pool itself, not inside read[].',
                $e->getMessage(),
            );
        }
    }
}
