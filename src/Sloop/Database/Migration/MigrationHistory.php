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
     * Ordered by batch, then by the order the rows were recorded. Not by name:
     * the server sorts names by collation, and under MySQL 8.0's default one
     * `_` comes before a digit, while the migrator applies files in byte order,
     * where the digit comes first.
     *
     * @return list<string>
     * @throws UnexpectedValueException If a name comes back as anything but a string
     */
    public function appliedNames(): array
    {
        $values = $this->connection->select()
            ->from($this->connection->migrationsTable())
            ->orderBy('batch')
            ->orderBy('id')
            ->pluck('name');

        $names = [];

        foreach ($values as $value) {
            $names[] = $this->name($value);
        }

        return $names;
    }

    /**
     * List the names of the migrations in the most recent batches, newest first.
     *
     * Batches are counted as they appear in the table, not by their numbers,
     * so a gap between batch numbers does not shorten the list. Within a batch
     * the migrations come in the reverse of the order they were recorded, the
     * order in which they can be undone.
     *
     * @param  int                      $batches How many of the most recent batches to list
     * @return list<string>
     * @throws UnexpectedValueException If a name comes back as anything but a string, or a batch as anything but an integer
     */
    public function namesInLastBatches(int $batches): array
    {
        $rows = $this->connection->select('name', 'batch')
            ->from($this->connection->migrationsTable())
            ->orderBy('batch', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        $namesByBatch = [];

        foreach ($rows as $row) {
            $namesByBatch[$this->batch($row['batch'] ?? null)][] = $this->name($row['name'] ?? null);
        }

        return array_merge(...\array_slice($namesByBatch, 0, $batches));
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

        return $this->batch($batch);
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

    /**
     * Remove the record of a migration, once it has been undone.
     *
     * @param  string $name Migration name: the file name without the extension
     * @return void
     */
    public function delete(string $name): void
    {
        $this->connection->delete($this->connection->migrationsTable())
            ->where('name', $name)
            ->execute();
    }

    /**
     * Check a name read from the table.
     *
     * @param  mixed                    $value Value read from the name column
     * @return string
     * @throws UnexpectedValueException If the value is not a string
     */
    private function name(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new UnexpectedValueException(
                'Migration history name must be a string, got ' . get_debug_type($value) . '.',
            );
        }

        return $value;
    }

    /**
     * Check a batch number read from the table.
     *
     * @param  mixed                    $value Value read from the batch column
     * @return int
     * @throws UnexpectedValueException If the value is not an integer
     */
    private function batch(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new UnexpectedValueException(
                'Migration history batch must be an integer, got ' . get_debug_type($value) . '.',
            );
        }

        return $value;
    }
}
