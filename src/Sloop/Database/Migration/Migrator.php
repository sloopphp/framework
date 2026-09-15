<?php

declare(strict_types=1);

namespace Sloop\Database\Migration;

use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Sloop\Database\Connection;
use Sloop\Database\Exception\DatabaseException;
use Throwable;
use UnexpectedValueException;

/**
 * Applies the migrations in a directory that have not run yet, undoes applied ones, and reports where each stands.
 *
 * Migrations run one at a time over the given connection, oldest first.
 * None of them is wrapped in a transaction: MySQL and MariaDB commit any open
 * transaction when a DDL statement runs, so a wrapper would protect nothing
 * the moment a migration changed the schema, and its commit would then fail.
 * A migration that only changes data and needs that protection opens a
 * transaction itself inside up().
 */
final readonly class Migrator
{
    /**
     * Directory the migration files are read from.
     *
     * @var MigrationDirectory
     */
    private MigrationDirectory $directory;

    /**
     * History table of the connection.
     *
     * @var MigrationHistory
     */
    private MigrationHistory $history;

    /**
     * Prepare to migrate a connection from a directory of migration files.
     *
     * @param  Connection               $connection Connection the migrations run over and are recorded on
     * @param  string                   $directory  Directory holding the migration files
     * @throws InvalidArgumentException If the directory does not exist
     */
    public function __construct(
        private Connection $connection,
        string $directory,
    ) {
        $this->directory = new MigrationDirectory($directory);
        $this->history   = new MigrationHistory($connection);
    }

    /**
     * Apply every migration that has not run yet.
     *
     * The migrations applied by one call share a batch number, one higher than
     * the last. When a migration throws, any transaction it left open is rolled
     * back and the exception is passed on untouched: the ones before it stay
     * applied and recorded, and it and the ones after it run on the next call.
     *
     * @return int                      Number of migrations applied
     * @throws LogicException           If the connection is inside a transaction, or a migration leaves one open
     * @throws RuntimeException         If the directory cannot be read
     * @throws UnexpectedValueException If a file breaks the naming convention, two files would declare the same class, or a file does not declare a usable migration class
     */
    public function run(): int
    {
        if ($this->connection->inTransaction()) {
            throw new LogicException(
                'Cannot run migrations inside a transaction: the first schema change would commit it.',
            );
        }

        $files = $this->directory->files();

        $this->history->createIfMissing();

        $applied = array_flip($this->history->appliedNames());
        $pending = [];

        foreach ($files as $file) {
            if (!isset($applied[$file->name])) {
                $pending[] = $file;
            }
        }

        $batch = $this->history->lastBatch() + 1;

        foreach ($pending as $file) {
            $migration = $this->load($file);

            $this->perform(
                $file->name,
                fn () => $migration->up($this->connection),
                'up',
                'was not recorded',
            );

            $this->history->record($file->name, $batch);
        }

        return \count($pending);
    }

    /**
     * Undo the most recent batch, or the given number of the most recent migrations.
     *
     * The migrations one call to run() applied form a batch. Without a count,
     * the most recent batch is undone. With one, that many migrations are
     * undone, counting back across batches. Either way down() is called from
     * the last applied back to the first. A count larger than the number
     * recorded undoes every migration.
     *
     * Every migration to undo must still have its file, declaring a usable
     * class, and all of them are checked before the first down() is called.
     * When a migration throws, any transaction it left open is rolled back and
     * the exception is passed on untouched: the ones undone before it stay out
     * of the history, and it and the ones after it remain recorded. The count
     * is taken from what is still recorded on each call, so after a failure
     * pass the number of migrations that are still to be undone.
     *
     * @param  int|null                 $steps Number of migrations to undo, or null for the most recent batch
     * @return int                      Number of migrations undone
     * @throws InvalidArgumentException If the number of migrations is less than 1
     * @throws LogicException           If the connection is inside a transaction, or a migration leaves one open
     * @throws RuntimeException         If the directory cannot be read
     * @throws UnexpectedValueException If a file breaks the naming convention, two files would declare the same class, a migration to undo has no file, or its file does not declare a usable migration class
     */
    public function rollback(?int $steps = null): int
    {
        if ($steps !== null && $steps < 1) {
            throw new InvalidArgumentException('Rollback steps must be 1 or greater, got ' . $steps . '.');
        }

        return $this->undo(
            fn (): array => $steps === null ? $this->history->namesInLastBatch() : $this->history->lastNames($steps),
        );
    }

    /**
     * Undo every migration that has been applied, newest first.
     *
     * Behaves as rollback() given a count of every recorded migration: the
     * same checks run before the first down(), and a failure leaves the ones
     * before it undone and the rest recorded. Every table the migrations
     * created is dropped along the way, so this is for development databases.
     *
     * @return int                      Number of migrations undone
     * @throws LogicException           If the connection is inside a transaction, or a migration leaves one open
     * @throws RuntimeException         If the directory cannot be read
     * @throws UnexpectedValueException If a file breaks the naming convention, two files would declare the same class, a migration to undo has no file, or its file does not declare a usable migration class
     */
    public function reset(): int
    {
        return $this->undo(fn (): array => array_reverse($this->history->appliedNames()));
    }

    /**
     * List every migration in the directory or the history, and where it stands.
     *
     * Ordered by name. A migration recorded in the history whose file is gone
     * is listed too, with hasFile false. Nothing is written: when the history
     * table does not exist yet, every migration is listed as not applied and
     * the table is not created, so this can be called inside a transaction.
     *
     * @return list<MigrationStatus>
     * @throws RuntimeException         If the directory cannot be read
     * @throws UnexpectedValueException If a file breaks the naming convention, two files would declare the same class, or a history row cannot be read
     */
    public function status(): array
    {
        $files    = array_column($this->directory->files(), null, 'name');
        $statuses = [];

        foreach ($this->history->exists() ? $this->history->records() : [] as $record) {
            $statuses[$record['name']] = new MigrationStatus(
                $record['name'],
                $record['batch'],
                $record['appliedAt'],
                isset($files[$record['name']]),
            );
        }

        foreach (array_keys($files) as $name) {
            $statuses[$name] ??= new MigrationStatus($name, null, null, true);
        }

        ksort($statuses, \SORT_STRING);

        return array_values($statuses);
    }

    /**
     * Undo the migrations a caller picks from the history, in the order given.
     *
     * The names are read once the history table is known to exist, so the
     * callback runs after the checks that must come first.
     *
     * @param  Closure(): list<string>  $names Reads the names of the migrations to undo, in the order to undo them
     * @return int                      Number of migrations undone
     * @throws LogicException           If the connection is inside a transaction, or a migration leaves one open
     * @throws RuntimeException         If the directory cannot be read
     * @throws UnexpectedValueException If a file breaks the naming convention, two files would declare the same class, a migration to undo has no file, or its file does not declare a usable migration class
     */
    private function undo(Closure $names): int
    {
        if ($this->connection->inTransaction()) {
            throw new LogicException(
                'Cannot roll back migrations inside a transaction: the first schema change would commit it.',
            );
        }

        $files = array_column($this->directory->files(), null, 'name');

        $this->history->createIfMissing();

        $names   = $names();
        $missing = array_diff($names, array_keys($files));

        if ($missing !== []) {
            throw new UnexpectedValueException(
                'Migrations recorded as applied have no file in the migration directory: ' . implode(', ', $missing) . '.',
            );
        }

        $migrations = [];

        foreach ($names as $name) {
            $migrations[$name] = $this->load($files[$name]);
        }

        foreach ($migrations as $name => $migration) {
            $this->perform(
                $name,
                fn () => $migration->down($this->connection),
                'down',
                'was left in the history',
            );

            $this->history->delete($name);
        }

        return \count($migrations);
    }

    /**
     * Call up() or down() on a migration, and make sure it left no transaction open.
     *
     * A transaction left open is rolled back and refused, because the history
     * change that follows would join it and vanish with it if it never
     * committed. When the migration throws, a transaction it left open is
     * rolled back and the exception is passed on untouched.
     *
     * @param  string          $name           Migration name, for the message
     * @param  Closure(): void $call           Call to the migration's method
     * @param  string          $method         Name of the method called, for the message
     * @param  string          $historyOutcome What became of the history entry, for the message
     * @return void
     * @throws LogicException  If the migration left a transaction open
     */
    private function perform(string $name, Closure $call, string $method, string $historyOutcome): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            $this->rollBackLeftOpen();

            throw $e;
        }

        if ($this->connection->inTransaction()) {
            $this->connection->rollback();

            throw new LogicException(
                'Migration ' . $name . ' left a transaction open: it was rolled back and the migration '
                . $historyOutcome . '. Commit or roll back inside ' . $method . '().',
            );
        }
    }

    /**
     * Roll back a transaction a failing migration left open.
     *
     * Runs while the migration's exception is on its way out, so a failed
     * rollback is dropped rather than thrown in its place: the caller needs
     * the exception that stopped the migration.
     *
     * @return void
     */
    private function rollBackLeftOpen(): void
    {
        if (!$this->connection->inTransaction()) {
            return;
        }

        try {
            $this->connection->rollback();
        } catch (DatabaseException) {
        }
    }

    /**
     * Load a migration file and create the migration it declares.
     *
     * The file is required from a static closure so it sees none of this
     * object's scope. Its class is looked up without autoloading, and must be
     * declared by that very file: a class of the same name declared elsewhere
     * would otherwise run in its place.
     *
     * @param  MigrationFile            $file File to load
     * @return Migration
     * @throws UnexpectedValueException If the file cannot be read or does not declare its class, the class is declared in another file, or it is not a concrete Migration that takes no constructor arguments
     */
    private function load(MigrationFile $file): Migration
    {
        $className = $file->className;

        if (!class_exists($className, false)) {
            if (!is_readable($file->path)) {
                throw new UnexpectedValueException('Migration file ' . $file->path . ' cannot be read.');
            }

            (static function (string $path): void {
                require_once $path;
            })($file->path);
        }

        if (!class_exists($className, false)) {
            throw new UnexpectedValueException(
                'Migration file ' . $file->path . ' must declare class ' . $className . ', without a namespace.',
            );
        }

        $class      = new ReflectionClass($className);
        $declaredIn = $class->getFileName();

        if ($declaredIn !== realpath($file->path)) {
            throw new UnexpectedValueException(
                'Class ' . $className . ' of migration file ' . $file->path . ' is already declared in '
                . ($declaredIn === false ? 'a built-in extension' : $declaredIn) . '.',
            );
        }

        if (!is_subclass_of($className, Migration::class)) {
            throw new UnexpectedValueException(
                'Class ' . $className . ' in migration file ' . $file->path . ' must extend ' . Migration::class . '.',
            );
        }

        $constructor = $class->getConstructor();

        if (!$class->isInstantiable() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            throw new UnexpectedValueException(
                'Class ' . $className . ' in migration file ' . $file->path
                . ' must be a concrete class whose constructor takes no required arguments.',
            );
        }

        return new $className();
    }
}
