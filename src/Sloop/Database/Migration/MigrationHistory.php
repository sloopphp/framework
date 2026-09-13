<?php

declare(strict_types=1);

namespace Sloop\Database\Migration;

use InvalidArgumentException;
use Sloop\Database\Connection;
use UnexpectedValueException;

/**
 * The table that records which migrations have run, and in which batch.
 *
 * The table is named by the connection (`migrations` unless the pool sets
 * `migrations_table`) and carries the table prefix like any other table the
 * framework writes. Each row is one migration, identified by its file name
 * without the extension; the migrations applied by one run share a batch
 * number, which is what a rollback undoes together.
 *
 * @internal Read and written by the migrator; applications do not touch the table directly.
 */
final readonly class MigrationHistory
{
    /**
     * Point at the history table of a connection.
     *
     * @param Connection $connection Connection the table lives on
     */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Create the table unless it already exists.
     *
     * @return void
     */
    public function createIfMissing(): void
    {
        $this->connection->statement(
            'CREATE TABLE IF NOT EXISTS ' . $this->connection->quoteTable($this->connection->migrationsTable()) . ' ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`name` VARCHAR(255) NOT NULL, '
            . '`batch` INT UNSIGNED NOT NULL, '
            . '`applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'PRIMARY KEY (`id`), '
            . 'UNIQUE KEY `uk_migrations_name` (`name`)'
            . ')',
        );
    }

    /**
     * List the names of the migrations that have run, in the order they ran.
     *
     * Ordered by batch, then by name, which is the order the migrator applies
     * files within a batch.
     *
     * @return list<string>
     * @throws UnexpectedValueException If a name comes back as anything but a string
     */
    public function appliedNames(): array
    {
        $values = $this->connection->select()
            ->from($this->connection->migrationsTable())
            ->orderBy('batch')
            ->orderBy('name')
            ->pluck('name');

        $names = [];

        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new UnexpectedValueException(
                    'Migration history name must be a string, got ' . get_debug_type($value) . '.',
                );
            }

            $names[] = $value;
        }

        return $names;
    }

    /**
     * Read the number of the most recent batch.
     *
     * @return int                      The highest batch number, or 0 when no migration has run
     * @throws UnexpectedValueException If the batch comes back as anything but an integer
     */
    public function lastBatch(): int
    {
        $batch = $this->connection->select()
            ->from($this->connection->migrationsTable())
            ->max('batch');

        if ($batch === null) {
            return 0;
        }

        if (!\is_int($batch)) {
            throw new UnexpectedValueException(
                'Migration history batch must be an integer, got ' . get_debug_type($batch) . '.',
            );
        }

        return $batch;
    }

    /**
     * Record that a migration has run.
     *
     * @param  string                   $name  Migration name: the file name without the extension
     * @param  int                      $batch Batch the migration ran in, starting at 1
     * @return void
     * @throws InvalidArgumentException If the batch is less than 1
     */
    public function record(string $name, int $batch): void
    {
        if ($batch < 1) {
            throw new InvalidArgumentException('Migration batch must be 1 or greater, got ' . $batch . '.');
        }

        $this->connection->insert($this->connection->migrationsTable())
            ->set(['name' => $name, 'batch' => $batch])
            ->execute();
    }
}
