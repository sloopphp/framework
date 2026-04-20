<?php

declare(strict_types=1);

namespace Sloop\Database;

use Closure;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\DeadlockException;
use Sloop\Database\Exception\ExceptionFactory;
use Sloop\Database\Exception\LockWaitTimeoutException;
use Throwable;

/**
 * PDO wrapper exposing sloop's database API.
 *
 * Provides raw execution (query/statement), transaction control with explicit
 * isolation levels and opt-in deadlock retry, and lazy server-dialect detection.
 *
 * sloop's minimum PDO defaults (EMULATE_PREPARES=false / ERRMODE_EXCEPTION /
 * FETCH_ASSOC / STRINGIFY_FETCHES=false) are applied by Connection::open().
 * Callers that inject a custom PDO are responsible for configuring equivalent
 * attributes themselves — the constructor does not mutate the injected PDO.
 */
final class Connection
{
    /**
     * sloop's minimum PDO attribute defaults applied by open().
     *
     * @var array<int, mixed>
     */
    private const array DEFAULT_PDO_OPTIONS = [
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    /**
     * Detected server dialect; populated lazily on first call to dialect().
     *
     * @var Dialect|null
     */
    private ?Dialect $dialect = null;

    /**
     * Raw server version string from SELECT VERSION(); populated lazily.
     *
     * @var string|null
     */
    private ?string $serverVersion = null;

    /**
     * Construct with an already-configured PDO.
     *
     * @param PDO    $pdo            PDO instance with sloop's default attributes applied
     * @param string $connectionName Identifier surfaced in exception context and logs
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $connectionName = '',
    ) {
    }

    /**
     * Open a new Connection from a DSN, applying sloop's PDO defaults internally.
     *
     * Internally constructs a PDO with `ATTR_EMULATE_PREPARES=false`,
     * `ATTR_ERRMODE=EXCEPTION`, `ATTR_DEFAULT_FETCH_MODE=FETCH_ASSOC`, and
     * `ATTR_STRINGIFY_FETCHES=false`. Higher-level options (connect timeout,
     * init command, query timeout, etc.) are passed through `$options` —
     * caller-provided keys win over the sloop defaults. Tests and advanced
     * callers that need to inject a prepared PDO (custom driver, stubs, etc.)
     * use the constructor directly instead.
     *
     * @param  string            $dsn            PDO DSN
     * @param  string|null       $username       Database user
     * @param  string|null       $password       Database password
     * @param  array<int, mixed> $options        Extra or override PDO attributes
     * @param  string            $connectionName Identifier for exception context and logs
     * @return self              New Connection wrapping the freshly built PDO
     * @throws DatabaseConnectionException When the connection cannot be established
     */
    public static function open(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        string $connectionName = '',
    ): self {
        $merged = $options + self::DEFAULT_PDO_OPTIONS;

        try {
            $pdo = new PDO($dsn, $username, $password, $merged);
        } catch (PDOException $e) {
            throw ExceptionFactory::fromPDOException($e, $connectionName);
        }

        return new self($pdo, $connectionName);
    }

    /**
     * Execute a SELECT-style statement and return the fetched rows.
     *
     * @param  string                   $sql      SQL statement returning a result set
     * @param  array<int|string, mixed> $bindings Parameters to bind
     * @return Result                   Fetched rows
     * @throws DatabaseException        When the statement fails
     */
    public function query(string $sql, array $bindings = []): Result
    {
        $stmt = $this->prepareAndExecute($sql, $bindings);
        // PDOStatement::fetchAll's stub only exposes `array`, so per-row
        // assert is required to narrow to Result's parameter type.
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            \assert(\is_array($row));
            $rows[] = $row;
        }

        return new Result($rows);
    }

    /**
     * Execute a DML/DDL statement and return the number of affected rows.
     *
     * DDL statements return 0 — MySQL/MariaDB do not report affected rows
     * for schema changes.
     *
     * @param  string                   $sql      SQL statement
     * @param  array<int|string, mixed> $bindings Parameters to bind
     * @return int                      Number of affected rows
     * @throws DatabaseException        When the statement fails
     */
    public function statement(string $sql, array $bindings = []): int
    {
        $stmt = $this->prepareAndExecute($sql, $bindings);

        return $stmt->rowCount();
    }

    /**
     * Begin a transaction, optionally applying an isolation level.
     *
     * MySQL/MariaDB's `SET TRANSACTION` applies only to the next single
     * transaction, so the session returns to the server default after
     * commit/rollback. Nested transactions are not supported in v0.1.
     *
     * @param  IsolationLevel    $level Isolation level (Default leaves the server default)
     * @return void
     * @throws LogicException    When another transaction is already active
     * @throws DatabaseException When the server rejects BEGIN or SET TRANSACTION
     */
    public function begin(IsolationLevel $level = IsolationLevel::Default): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Cannot begin a transaction while another is active (nesting is not supported).');
        }

        $setTransaction = $level->toSqlStatement();
        if ($setTransaction !== '') {
            $this->execSimple($setTransaction);
        }

        try {
            $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            throw ExceptionFactory::fromPDOException($e, $this->connectionName, 'BEGIN');
        }
    }

    /**
     * Commit the active transaction.
     *
     * @return void
     * @throws LogicException    When no transaction is active
     * @throws DatabaseException When COMMIT fails
     */
    public function commit(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Cannot commit: no active transaction.');
        }

        try {
            $this->pdo->commit();
        } catch (PDOException $e) {
            throw ExceptionFactory::fromPDOException($e, $this->connectionName, 'COMMIT');
        }
    }

    /**
     * Roll back the active transaction.
     *
     * @return void
     * @throws LogicException    When no transaction is active
     * @throws DatabaseException When ROLLBACK fails
     */
    public function rollback(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Cannot rollback: no active transaction.');
        }

        try {
            $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw ExceptionFactory::fromPDOException($e, $this->connectionName, 'ROLLBACK');
        }
    }

    /**
     * Report whether this connection is inside an active transaction.
     *
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Run $callback inside a transaction, rolling back on exception.
     *
     * When $callback (or the commit itself) throws DeadlockException or
     * LockWaitTimeoutException, the transaction is retried up to $maxAttempts
     * times with $backoffMs milliseconds of linear sleep between attempts.
     * Any other exception is re-thrown immediately after rollback.
     *
     * @template TReturn
     * @param  Closure(self): TReturn $callback    Receives this Connection
     * @param  IsolationLevel         $level       Isolation level for each attempt
     * @param  int                    $maxAttempts Maximum attempts (1 = no retry)
     * @param  int                    $backoffMs   Milliseconds between retries
     * @return TReturn                Return value from the successful attempt
     * @throws LogicException         When arguments are invalid or already in a transaction
     * @throws DatabaseException      When begin/commit fails or retries are exhausted
     *
     * @noinspection PhpDocMissingThrowsInspection — callback-thrown exceptions rethrown unchanged per coding-standards
     * @noinspection PhpUnhandledExceptionInspection
     */
    public function transaction(
        Closure $callback,
        IsolationLevel $level = IsolationLevel::Default,
        int $maxAttempts = 1,
        int $backoffMs = 0,
    ): mixed {
        if ($maxAttempts < 1) {
            throw new LogicException('maxAttempts must be at least 1, got ' . $maxAttempts . '.');
        }

        if ($backoffMs < 0) {
            throw new LogicException('backoffMs must not be negative, got ' . $backoffMs . '.');
        }

        if ($this->pdo->inTransaction()) {
            throw new LogicException('Cannot start a nested transaction (v0.1 does not support savepoints).');
        }

        for ($attempt = 1;; $attempt++) {
            $this->begin($level);

            try {
                $result = $callback($this);
                $this->pdo->commit();

                return $result;
            } catch (Throwable $e) {
                $thrown = $this->rollbackAndNormalize($e);

                if ($this->shouldRetry($thrown, $attempt, $maxAttempts)) {
                    $this->sleepBackoff($backoffMs);

                    continue;
                }

                throw $thrown;
            }
        }
    }

    /**
     * Detected server dialect (MySQL or MariaDB).
     *
     * Cached after the first call.
     *
     * @return Dialect
     * @throws DatabaseException When `SELECT VERSION()` fails
     */
    public function dialect(): Dialect
    {
        $this->ensureDialectDetected();

        \assert($this->dialect !== null);

        return $this->dialect;
    }

    /**
     * Raw `SELECT VERSION()` output.
     *
     * Cached after the first call.
     *
     * @return string
     * @throws DatabaseException When `SELECT VERSION()` fails
     */
    public function serverVersion(): string
    {
        $this->ensureDialectDetected();

        \assert($this->serverVersion !== null);

        return $this->serverVersion;
    }

    /**
     * Roll back silently (if still in a transaction) and normalize $e to a sloop exception.
     *
     * @param  Throwable $e Exception that aborted the transaction body
     * @return Throwable    Normalized exception (PDOException wrapped, others unchanged)
     */
    private function rollbackAndNormalize(Throwable $e): Throwable
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
                // Swallow: surface the original exception to the caller.
            }
        }

        return $e instanceof PDOException
            ? ExceptionFactory::fromPDOException($e, $this->connectionName)
            : $e;
    }

    /**
     * Decide whether the failed attempt should be retried.
     *
     * @param  Throwable $e           Normalized exception from the failed attempt
     * @param  int       $attempt     1-based index of the attempt that failed
     * @param  int       $maxAttempts Maximum attempts allowed
     * @return bool                   True if a retry is warranted and allowed
     */
    private function shouldRetry(Throwable $e, int $attempt, int $maxAttempts): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        return $e instanceof DeadlockException || $e instanceof LockWaitTimeoutException;
    }

    /**
     * Sleep between retries.
     *
     * @param  int  $backoffMs Milliseconds to sleep (0 or less means no sleep)
     * @return void
     */
    private function sleepBackoff(int $backoffMs): void
    {
        if ($backoffMs > 0) {
            usleep($backoffMs * 1000);
        }
    }

    /**
     * Probe the server version on first use and cache the result.
     *
     * @return void
     * @throws DatabaseException When `SELECT VERSION()` fails
     */
    private function ensureDialectDetected(): void
    {
        if ($this->dialect !== null) {
            return;
        }

        try {
            $statement = $this->pdo->query('SELECT VERSION()');
        } catch (PDOException $e) {
            throw ExceptionFactory::fromPDOException($e, $this->connectionName, 'SELECT VERSION()');
        }

        // Defensive: unreachable under ERRMODE_EXCEPTION (contractual for open()),
        // but tolerate callers that inject a PDO with a different error mode.
        $value = $statement === false ? '' : (string) $statement->fetchColumn();

        $this->serverVersion = $value;
        $this->dialect       = Dialect::detect($value);
    }

    /**
     * Execute a simple non-result SQL statement with no bindings.
     *
     * @param  string            $sql SQL to execute
     * @return void
     * @throws DatabaseException When the statement fails
     */
    private function execSimple(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            throw ExceptionFactory::fromPDOException($e, $this->connectionName, $sql);
        }
    }

    /**
     * Prepare and execute $sql with $bindings, wrapping any PDOException.
     *
     * @param  string                   $sql      SQL statement
     * @param  array<int|string, mixed> $bindings Parameters to bind
     * @return PDOStatement             Executed statement
     * @throws DatabaseException        When the statement fails
     */
    private function prepareAndExecute(string $sql, array $bindings): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);

            return $stmt;
        } catch (PDOException $e) {
            throw ExceptionFactory::fromPDOException($e, $this->connectionName, $sql, $bindings);
        }
    }
}
