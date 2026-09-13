<?php

declare(strict_types=1);

namespace Sloop\Database\Migration;

use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Sloop\Database\Connection;
use UnexpectedValueException;

/**
 * Applies the migrations in a directory that have not run yet.
 *
 * Migrations run one at a time over the given connection, oldest first, and
 * each is recorded in the history table as soon as its up() returns. None of
 * them is wrapped in a transaction: MySQL and MariaDB commit any open
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
     * the last. When a migration throws, the exception is passed on untouched:
     * the ones before it stay applied and recorded, and it and the ones after it
     * run on the next call.
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
            $this->load($file)->up($this->connection);

            // The history row would join a transaction the migration left
            // open, and vanish with it if that transaction never commits.
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();

                throw new LogicException(
                    'Migration ' . $file->name . ' left a transaction open: it was rolled back and the migration '
                    . 'was not recorded. Commit or roll back inside up().',
                );
            }

            $this->history->record($file->name, $batch);
        }

        return \count($pending);
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
     * @throws UnexpectedValueException If the file does not declare its class, the class is declared in another file, or it is not a concrete Migration that takes no constructor arguments
     */
    private function load(MigrationFile $file): Migration
    {
        $className = $file->className;

        if (!class_exists($className, false)) {
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
