<?php

declare(strict_types=1);

namespace Sloop\Database;

use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sloop\Database\Exception\DatabaseConnectionException;
use Sloop\Database\Exception\DatabaseException;
use Sloop\Database\Exception\DeadlockException;
use Sloop\Database\Exception\ExceptionFactory;
use Sloop\Database\Exception\LockWaitTimeoutException;
use Sloop\Database\Query\Expression;
use Sloop\Database\Query\Grammar;
use Sloop\Database\Query\Select;
use Throwable;
use UnexpectedValueException;

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
     * PSR-3 logger injected by ConnectionManager via setLogger(); null until injected.
     *
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger = null;

    /**
     * Per-connection logging behavior; replaced by setLogger() to match the configured pool settings.
     *
     * @var LoggingOptions
     */
    private LoggingOptions $loggingOptions;

    /**
     * Grammar handed to every query builder this connection starts.
     *
     * Defaults to one without a table prefix; ConnectionManager replaces it
     * with one built from the pool configuration.
     *
     * @var Grammar
     */
    private Grammar $grammar;

    /**
     * Per-session query timeout in milliseconds; null disables it. Set via setQueryTimeoutMs().
     *
     * @var int|null
     */
    private ?int $queryTimeoutMs = null;

    /**
     * Whether the configured timeout has already been issued to the server on this connection.
     *
     * Toggled true after a successful SET SESSION (max_execution_time on MySQL,
     * max_statement_time on MariaDB) so subsequent queries skip the redundant
     * statement. Reset to false when setQueryTimeoutMs() is called again.
     *
     * @var bool
     */
    private bool $queryTimeoutApplied = false;

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
        $this->loggingOptions = new LoggingOptions();
        $this->grammar        = new Grammar();
    }

    /**
     * Start a SELECT statement over this connection.
     *
     * The builder is handed this connection's grammar, so the table prefix and
     * the dialect come from the pool the connection belongs to rather than from
     * the builder.
     *
     * A statement started here runs on this connection and has nothing left to
     * route, which is what separates it from ConnectionManager::select(): that
     * one leaves the choice between primary and replica until the statement
     * runs.
     *
     * @param  string|Expression ...$columns Columns to select; none selects every column
     * @return Select            Builder for the statement
     */
    public function select(string|Expression ...$columns): Select
    {
        return new Select(new FixedConnectionRoute($this), $this->grammar, ...$columns);
    }

    /**
     * Replace the grammar handed to the query builders this connection starts.
     *
     * ConnectionManager calls this with a grammar carrying the pool's table
     * prefix, which is why the builders never build a grammar themselves.
     * The parts a Grammar reads and returns are internal to the seam between
     * it and a query builder, so replacing it is a framework-side extension
     * point rather than a supported way to write another dialect from outside.
     *
     * @param  Grammar $grammar Grammar to write the SQL of subsequent builders
     * @return void
     */
    public function setGrammar(Grammar $grammar): void
    {
        $this->grammar = $grammar;
    }

    /**
     * Inject a PSR-3 logger and the per-connection logging options.
     *
     * Failure logging is unconditional once a logger is present. The options
     * gate slow-query / log-all-queries output and binding redaction.
     * Tests and callers that don't need query logging can leave the logger
     * unset; the connection then logs nothing.
     *
     * @param  LoggerInterface $logger  PSR-3 logger (typically the `database` channel from LogManager)
     * @param  LoggingOptions  $options Logging behavior: bindings redaction, log-all-queries, slow threshold
     * @return void
     */
    public function setLogger(LoggerInterface $logger, LoggingOptions $options): void
    {
        $this->logger         = $logger;
        $this->loggingOptions = $options;
    }

    /**
     * Set the per-session query timeout in milliseconds (null disables it).
     *
     * Stored on the Connection but not issued to the server until the first
     * query() or statement() call: dialect detection (`SELECT VERSION()`)
     * has to run first to choose between MySQL `max_execution_time` and
     * MariaDB `max_statement_time`. Calling this resets the "applied" flag
     * so a fresh value will take effect on the next query — but a previously
     * applied value remains in effect on the server session until the next
     * SET SESSION fires (passing null does not retroactively clear it).
     *
     * Intended for one-time configuration before the first query runs.
     *
     * @param  int|null $ms Timeout in milliseconds (positive int) or null to disable
     * @return void
     */
    public function setQueryTimeoutMs(?int $ms): void
    {
        $this->queryTimeoutMs      = $ms;
        $this->queryTimeoutApplied = false;
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
     * @param  string                      $dsn            PDO DSN
     * @param  string|null                 $username       Database user
     * @param  string|null                 $password       Database password
     * @param  array<int, mixed>           $options        Extra or override PDO attributes
     * @param  string                      $connectionName Identifier for exception context and logs
     * @return self                        New Connection wrapping the freshly built PDO
     * @throws DatabaseConnectionException When the connection cannot be established
     */
    public static function open(
        string $dsn,
        ?string $username = null,
        #[\SensitiveParameter]
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
     * A timeout given here applies to this statement alone. How it is written
     * differs between the server flavors, so the statement is rewritten for
     * the one this connection reached; what that rewriting looks like, and
     * what the server does when the limit is hit, is in the database guide.
     *
     * @param  string                   $sql       SQL statement returning a result set
     * @param  array<int|string, mixed> $bindings  Parameters to bind
     * @param  int|null                 $timeoutMs Milliseconds this statement may run for, or null to leave it to the session
     * @return Result                   Fetched rows
     * @throws InvalidArgumentException When the timeout is not positive, or the statement cannot carry one
     * @throws DatabaseException        When the statement fails
     * @throws UnexpectedValueException When PDO returns a non-array row under FETCH_ASSOC (driver contract violation)
     */
    public function query(string $sql, array $bindings = [], ?int $timeoutMs = null): Result
    {
        $this->applyQueryTimeoutIfPending();

        if ($timeoutMs !== null) {
            $sql = $this->withStatementTimeout($sql, $timeoutMs);
        }

        $startTime = $this->shouldMeasureElapsed() ? microtime(true) : null;

        try {
            $stmt = $this->prepareAndExecute($sql, $bindings);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!\is_array($row)) {
                    throw new UnexpectedValueException('PDO returned non-array row from FETCH_ASSOC');
                }
                $rows[] = self::narrowRow($row);
            }
        } catch (DatabaseException $e) {
            $this->logQueryFailure($sql, $bindings, $e);

            throw $e;
        }

        if ($startTime !== null && $this->logger !== null) {
            $this->logQuerySuccess($this->logger, $sql, $bindings, $startTime, isSelect: true);
        }

        return new Result($rows);
    }

    /**
     * Narrow one fetched row to the value types the drivers return.
     *
     * PDO's own signature is too wide to analyse statically, and carrying that
     * width into Result would push the ambiguity into every caller's code. With
     * EMULATE_PREPARES and STRINGIFY_FETCHES both off, the supported drivers
     * return only these four value types, so anything else means the contract
     * this class relies on no longer holds and is worth failing on rather than
     * passing along.
     *
     * Column names stay array-key rather than string: a numeric column name
     * such as the one `SELECT 1` produces arrives as the string "1", and PHP
     * casts numeric string keys to int on assignment. Declaring string here
     * would be a claim the language cannot honour.
     *
     * @param  array<array-key, mixed>                 $row Row as returned by PDO under FETCH_ASSOC
     * @return array<array-key, int|float|string|null> The same row, narrowed
     * @throws UnexpectedValueException                When a value falls outside the driver contract
     */
    private static function narrowRow(array $row): array
    {
        $narrowed = [];

        foreach ($row as $column => $value) {
            if ($value !== null && !\is_int($value) && !\is_float($value) && !\is_string($value)) {
                throw new UnexpectedValueException(
                    'PDO returned an unsupported value type for column "'
                        . $column . '": ' . get_debug_type($value),
                );
            }

            $narrowed[$column] = $value;
        }

        return $narrowed;
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
        $this->applyQueryTimeoutIfPending();

        $startTime = $this->shouldMeasureElapsed() ? microtime(true) : null;

        try {
            $stmt = $this->prepareAndExecute($sql, $bindings);
        } catch (DatabaseException $e) {
            $this->logQueryFailure($sql, $bindings, $e);

            throw $e;
        }

        if ($startTime !== null && $this->logger !== null) {
            $this->logQuerySuccess($this->logger, $sql, $bindings, $startTime, isSelect: false);
        }

        return $stmt->rowCount();
    }

    /**
     * Write a value the way SQL spells it, so a statement can be shown with its values in place.
     *
     * This is for reading a statement, not for building one. Text assembled
     * this way never reaches the server: the values a statement runs with are
     * bound, and binding is the boundary that keeps a value from being read as
     * SQL. Strings are handed to the driver rather than quoted here, so the
     * rendering follows the connection's own rules.
     *
     * Everything but null is written as a quoted string, because that is what
     * the server receives: bindings are passed to PDOStatement::execute() as
     * an array, which binds every one of them as a string. Writing a number
     * bare would describe a statement that can select different rows than the
     * one that ran — `code = 5` compares numerically and matches a stored
     * '5.0', while the `code = '5'` that actually runs does not.
     *
     * @param  string|int|float|bool|null $value Value to write out
     * @return string                     The value as SQL text
     * @throws RuntimeException           When the driver declines to quote a string
     *
     * @internal Rendering for Query::toRawSql().
     */
    public function quoteLiteral(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        // A cast spells a value the way PDO does when it binds it as a string,
        // which is the whole point here: a bool becomes '1' or the empty
        // string, and a float follows the precision ini setting exactly as the
        // driver's own conversion does. That is not the shortest text the float
        // reads back from — var_export writes that one, and 0.1 + 0.2 shows the
        // difference — but what the server receives is what this has to show.
        // NAN is the single value a cast has no spelling for, and warns on.
        $text = \is_float($value) && is_nan($value)
            ? var_export($value, true)
            : (string) $value;

        $quoted = $this->pdo->quote($text);

        if ($quoted === false) {
            throw new RuntimeException(
                'The database driver does not support quoting a string, so the statement cannot be shown with its values in place.',
            );
        }

        return $quoted;
    }

    /**
     * Begin a transaction, optionally applying an isolation level.
     *
     * MySQL/MariaDB's `SET TRANSACTION` applies only to the next single
     * transaction, so the session returns to the server default after
     * commit/rollback. Nested transactions are not supported.
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
            throw new LogicException('Cannot start a nested transaction (savepoints are not supported).');
        }

        for ($attempt = 1;; $attempt++) {
            $this->begin($level);

            try {
                $result = $callback($this);
                $this->pdo->commit();

                return $result;
            } catch (Throwable $e) {
                $thrown = $this->rollbackAndNormalize($e);

                // A failed rollback can leave the PDO transaction open; retrying
                // would then hit begin()'s nested-transaction guard and mask the
                // original exception with a LogicException. Surface the original.
                if ($this->shouldRetry($thrown, $attempt, $maxAttempts) && !$this->pdo->inTransaction()) {
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
        return $this->dialect ??= Dialect::detect($this->serverVersion());
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
        return $this->serverVersion ??= $this->probeServerVersion();
    }

    /**
     * Send a `DO 1` ping to confirm the underlying connection is still alive.
     *
     * Issued via `PDO::exec()` so the round-trip skips prepared-statement
     * setup; cost-wise comparable to MySQL's `COM_PING`. Detects connections
     * the server has silently closed (e.g. `wait_timeout`) before the next
     * real query would. Used by ConnectionManager's replica health check
     * after a successful PDO connect.
     *
     * @return void
     * @throws DatabaseException When the ping query fails
     */
    public function ping(): void
    {
        $this->execSimple('DO 1');
    }

    /**
     * Roll back silently (if still in a transaction) and normalize $e to a sloop exception.
     *
     * Rollback failures are logged at `warning` level (never thrown) because the
     * caller still needs the original exception. Rollback failure usually signals
     * connection drop or protocol breakage, so visibility matters even though
     * we do not surface it directly.
     *
     * @param  Throwable $e Exception that aborted the transaction body
     * @return Throwable Normalized exception (PDOException wrapped, others unchanged)
     */
    private function rollbackAndNormalize(Throwable $e): Throwable
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException $rollbackError) {
                $this->logger?->warning(
                    'rollback failed during exception unwind',
                    [
                        'rollback_error'     => $rollbackError->getMessage(),
                        'original_exception' => $e::class,
                        'original_message'   => $e->getMessage(),
                        'connection_name'    => $this->connectionName,
                    ],
                );
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
     * @return bool      True if a retry is warranted and allowed
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
     * Probe the server version via `SELECT VERSION()`; called lazily by serverVersion().
     *
     * @return string            Raw `SELECT VERSION()` output, or '' if the driver returned false instead of throwing
     * @throws DatabaseException When `SELECT VERSION()` fails
     */
    private function probeServerVersion(): string
    {
        try {
            $statement = $this->pdo->query('SELECT VERSION()');
        } catch (PDOException $e) {
            throw ExceptionFactory::fromPDOException($e, $this->connectionName, 'SELECT VERSION()');
        }

        // Defensive: unreachable under ERRMODE_EXCEPTION (contractual for open()),
        // but tolerate callers that inject a PDO with a different error mode.
        return $statement === false ? '' : (string) $statement->fetchColumn();
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
     * Issue the configured per-session query timeout to the server on the first call.
     *
     * The timeout statement differs between server flavors (MySQL uses
     * `max_execution_time` in milliseconds and applies only to SELECT;
     * MariaDB uses `max_statement_time` in fractional seconds and applies
     * to every statement), so dialect detection has to run before the
     * SET SESSION can be assembled. The result is cached in
     * $queryTimeoutApplied so subsequent queries do not re-issue it.
     *
     * Failures here are logged at `error` level when a logger is present
     * and then re-thrown so the caller learns the timeout was not applied.
     *
     * @return void
     * @throws DatabaseException When dialect detection or the SET SESSION fails
     */
    private function applyQueryTimeoutIfPending(): void
    {
        if ($this->queryTimeoutMs === null || $this->queryTimeoutApplied) {
            return;
        }

        try {
            $sql = match ($this->dialect()) {
                Dialect::MySQL   => 'SET SESSION max_execution_time = ' . $this->queryTimeoutMs,
                Dialect::MariaDB => 'SET SESSION max_statement_time = ' . \sprintf('%.3F', $this->queryTimeoutMs / 1000),
            };
            $this->execSimple($sql);
            $this->queryTimeoutApplied = true;
        } catch (DatabaseException $e) {
            $this->logger?->error(
                'failed to apply query timeout: ' . $e->getMessage(),
                [
                    'sqlstate'        => $e->sqlState,
                    'driver_code'     => $e->driverCode,
                    'connection_name' => $this->connectionName,
                ],
            );

            throw $e;
        }
    }

    /**
     * Rewrite a SELECT so the server gives up on it after the given time.
     *
     * MySQL takes the limit as an optimizer hint written into the select list
     * and counts in milliseconds; MariaDB takes it as a prefix that scopes a
     * SET to one statement and counts in seconds. Neither form is understood
     * by the other server, so which one is written depends on the dialect this
     * connection detected.
     *
     * Both forms attach to a statement that opens with SELECT, which is what
     * makes the demand for that opening a property of the rewriting rather
     * than a rule about which statements deserve a limit. Anything else is
     * refused here instead of on the server, so the same code is refused on
     * both flavors rather than on whichever one cannot parse the result.
     *
     * @param  string                   $sql       SQL of the statement to limit
     * @param  int                      $timeoutMs Milliseconds the statement may run for
     * @return string                   The statement carrying the limit
     * @throws InvalidArgumentException When the timeout is not positive, or the statement does not open with SELECT
     * @throws DatabaseException        When dialect detection fails
     */
    private function withStatementTimeout(string $sql, int $timeoutMs): string
    {
        if ($timeoutMs < 1) {
            throw new InvalidArgumentException(
                'A statement timeout is a count of milliseconds to run for, so it starts at 1; got ' . $timeoutMs . '.',
            );
        }

        if (!str_starts_with($sql, 'SELECT ')) {
            throw new InvalidArgumentException(
                'A statement timeout is written into the SELECT it limits, so the statement has to open with it.',
            );
        }

        return match ($this->dialect()) {
            Dialect::MySQL   => 'SELECT /*+ MAX_EXECUTION_TIME(' . $timeoutMs . ') */ ' . substr($sql, 7),
            Dialect::MariaDB => 'SET STATEMENT max_statement_time = '
                . \sprintf('%.3F', $timeoutMs / 1000) . ' FOR ' . $sql,
        };
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

    /**
     * Whether elapsed-time measurement is required for the upcoming query.
     *
     * Returns true only when a logger is set and at least one option that
     * consumes `elapsed_ms` is enabled. Lets query() / statement() skip both
     * `microtime(true)` calls in the default config (no logger or both
     * options off), where the measurement would otherwise be dead work.
     *
     * @return bool
     */
    private function shouldMeasureElapsed(): bool
    {
        return $this->logger !== null
            && ($this->loggingOptions->logAllQueries
                || $this->loggingOptions->slowQueryThresholdMs !== null);
    }

    /**
     * Log a successful query at `warning` (slow) or `debug` (log_all_queries) level when applicable.
     *
     * Slow-query logging fires for SELECT-style queries only; statement() never
     * triggers it because the threshold is intended for read-path latency budgets.
     * The caller passes a non-null logger so this helper can encode the
     * shouldMeasureElapsed() invariant into its signature without re-checking.
     *
     * @param  LoggerInterface          $logger    Logger narrowed by the caller (non-null)
     * @param  string                   $sql       Executed SQL
     * @param  array<int|string, mixed> $bindings  Bound parameters (redacted in context per LoggingOptions)
     * @param  float                    $startTime microtime(true) at the start of the call
     * @param  bool                     $isSelect  True when invoked from query(); false from statement()
     * @return void
     */
    private function logQuerySuccess(
        LoggerInterface $logger,
        string $sql,
        array $bindings,
        float $startTime,
        bool $isSelect,
    ): void {
        $elapsedMs = (microtime(true) - $startTime) * 1000;

        if ($isSelect
            && $this->loggingOptions->slowQueryThresholdMs !== null
            && $elapsedMs > $this->loggingOptions->slowQueryThresholdMs) {
            $logger->warning(
                'slow query',
                $this->buildLogContext($sql, $bindings) + ['elapsed_ms' => $elapsedMs],
            );

            return;
        }

        if ($this->loggingOptions->logAllQueries) {
            $logger->debug(
                'query executed',
                $this->buildLogContext($sql, $bindings) + ['elapsed_ms' => $elapsedMs],
            );
        }
    }

    /**
     * Log a failed query at `error` level. Failure logging is unconditional once a logger is injected.
     *
     * @param  string                   $sql      SQL that failed
     * @param  array<int|string, mixed> $bindings Bound parameters (redacted in context per LoggingOptions)
     * @param  DatabaseException        $e        The wrapped failure (carries sqlState / driverCode)
     * @return void
     */
    private function logQueryFailure(string $sql, array $bindings, DatabaseException $e): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->error(
            $e->getMessage(),
            $this->buildLogContext($sql, $bindings) + [
                'sqlstate'    => $e->sqlState,
                'driver_code' => $e->driverCode,
            ],
        );
    }

    /**
     * Build the log context fields shared between success and failure records.
     *
     * Bindings are replaced with the `[redacted]` sentinel string when
     * `log_bindings` is false. Dialect is included only when already detected
     * to avoid triggering an extra `SELECT VERSION()` from a failure log path.
     *
     * @param  string                   $sql      SQL being logged
     * @param  array<int|string, mixed> $bindings Bound parameters
     * @return array<string, mixed>     Context map with sql / bindings / connection_name / optional dialect
     */
    private function buildLogContext(string $sql, array $bindings): array
    {
        $context = [
            'sql'             => $sql,
            'bindings'        => $this->loggingOptions->logBindings ? $bindings : '[redacted]',
            'connection_name' => $this->connectionName,
        ];

        if ($this->dialect !== null) {
            $context['dialect'] = $this->dialect->name;
        }

        return $context;
    }
}
