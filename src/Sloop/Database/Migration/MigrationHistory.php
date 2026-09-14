<?php

declare(strict_types=1);

namespace Sloop\Database\Migration;

use DateTimeImmutable;
use InvalidArgumentException;
use Sloop\Database\CastMode;
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
     * Whether the table exists in the connection's current database.
     *
     * Asks information_schema rather than creating the table or reading it and
     * catching the failure: creating commits any open transaction, and a failed
     * read is logged as an error by the connection.
     *
     * The name asked for is the quoted, prefixed name with its backticks taken
     * off. The grammar accepts only letters, digits and underscores in a prefix,
     * and the `migrations_table` setting is held to the same, so for a
     * configured pool the quoting adds nothing else to take off.
     *
     * @return bool
     */
    public function exists(): bool
    {
        $quoted = $this->connection->quoteTable($this->connection->migrationsTable());

        return !$this->connection->query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [substr($quoted, 1, -1)],
        )->isEmpty();
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
     * Read every recorded migration with its batch and the time it was recorded.
     *
     * The time is read as the server writes the column, rather than under the
     * pool's CastMode, so it comes back the same way whatever the pool is
     * configured with, and is read as a time in PHP's default timezone.
     *
     * @return list<array{name: string, batch: int, appliedAt: DateTimeImmutable}> In the order the rows were recorded
     * @throws UnexpectedValueException                                            If a name is not a string, a batch not an integer, or a time not written as Y-m-d H:i:s
     */
    public function records(): array
    {
        $rows = $this->connection->select('name', 'batch', 'applied_at')
            ->from($this->connection->migrationsTable())
            ->orderBy('id')
            ->castMode(CastMode::Off)
            ->get();

        $records = [];

        foreach ($rows as $row) {
            $records[] = [
                'name'      => $this->name($row['name'] ?? null),
                'batch'     => $this->batch($row['batch'] ?? null),
                'appliedAt' => $this->appliedAt($row['applied_at'] ?? null),
            ];
        }

        return $records;
    }

    /**
     * List the names of the migrations in the most recent batch, newest first.
     *
     * The most recent batch is the highest batch number in the table. Within
     * it the migrations come in the reverse of the order they were recorded,
     * the order in which they can be undone.
     *
     * @return list<string>
     * @throws UnexpectedValueException If a name comes back as anything but a string, or a batch as anything but an integer
     */
    public function namesInLastBatch(): array
    {
        return $this->namesByBatchNewestFirst()[0] ?? [];
    }

    /**
     * List the names of the most recently applied migrations, newest first.
     *
     * Counted across batches: the most recent batch first, and within a batch
     * in the reverse of the order they were recorded.
     *
     * @param  int                      $count How many migrations to list at most
     * @return list<string>
     * @throws UnexpectedValueException If a name comes back as anything but a string, or a batch as anything but an integer
     */
    public function lastNames(int $count): array
    {
        return \array_slice(array_merge(...$this->namesByBatchNewestFirst()), 0, $count);
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
     * Read every name, grouped by batch, the most recent batch first.
     *
     * Ordered by id within a batch rather than by name, for the reason
     * appliedNames() gives.
     *
     * @return list<list<string>>       Names of each batch, newest first
     * @throws UnexpectedValueException If a name comes back as anything but a string, or a batch as anything but an integer
     */
    private function namesByBatchNewestFirst(): array
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

        return array_values($namesByBatch);
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
     * Read a time from the table.
     *
     * The value must have the shape the server writes the column in, and must
     * be a real date: a day PHP would roll into the next month, such as the
     * 30th of February, is refused. A wall-clock time that PHP's default
     * timezone skips when it moves to summer time is still accepted, since the
     * server may have written it under a timezone that does not skip it.
     *
     * @param  mixed                    $value Value read from the applied_at column
     * @return DateTimeImmutable
     * @throws UnexpectedValueException If the value is not a string written as Y-m-d H:i:s, or not a real date, or
     *                                  the pattern that checks the shape could not run
     */
    private function appliedAt(mixed $value): DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new UnexpectedValueException(
                'Migration history applied_at must be a time written as Y-m-d H:i:s, got ' . get_debug_type($value) . '.',
            );
        }

        $shaped = preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value);

        // A pattern that did not run says nothing about the value, so it is
        // not reported as a malformed one.
        if ($shaped === false) {
            throw new UnexpectedValueException(
                'Migration history applied_at could not be checked (' . preg_last_error_msg() . ').',
            );
        }

        $time = $shaped === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value) : false;

        if ($time === false || DateTimeImmutable::getLastErrors() !== false) {
            throw new UnexpectedValueException(
                'Migration history applied_at must be a time written as Y-m-d H:i:s, got string \'' . $value . '\'.',
            );
        }

        return $time;
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
