<?php

declare(strict_types=1);

namespace Sloop\Tests\Integration\Database;

use Sloop\Database\Result;
use Sloop\Tests\Support\TransactionalIntegrationTestCase;

// The unit tests run these walks against SQLite, which answers the same for a
// table nobody else is touching. What only a real server shows is what happens
// when the rows move underneath the walk: chunk() addresses them by position
// and chunkById() by value, and that difference is the reason the guide tells
// callers to reach for the second one on a table being written to.
final class SelectWalkTest extends TransactionalIntegrationTestCase
{
    private string $walked = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->walked = '';

        $rows = [];
        $args = [];

        for ($id = 1; $id <= 6; $id++) {
            $rows[] = '(?, ?, ?, ?, NOW())';
            $args   = [...$args, $id, 'user' . $id, 'user' . $id . '@example.com', 'active'];
        }

        $this->connection->statement(
            'INSERT INTO users (id, name, email, status, created_at) VALUES ' . implode(', ', $rows),
            $args,
        );
    }

    private static function idsOf(Result $batch): string
    {
        $ids = [];

        foreach ($batch as $row) {
            $ids[] = (string) $row['id'];
        }

        return implode(',', $ids);
    }

    private function record(Result $batch, int $index): string
    {
        // Held as text rather than a list so that the batch boundaries and
        // their numbering land in the same assertion as the ids, and so that
        // the walks can collect without a closure inheriting by reference.
        $ids           = self::idsOf($batch);
        $this->walked .= '|' . $index . ':' . $ids;

        return $ids;
    }

    public function testChunkWalksEveryRowOnTheServer(): void
    {
        $finished = $this->connection->select('id')->from('users')->orderBy('id')
            ->chunk(4, $this->record(...));

        $this->assertTrue($finished);
        $this->assertSame('|0:1,2,3,4|1:5,6', $this->walked);
    }

    public function testChunkByIdWalksEveryRowOnTheServer(): void
    {
        $finished = $this->connection->select('id')->from('users')->chunkById(4, $this->record(...));

        $this->assertTrue($finished);
        $this->assertSame('|0:1,2,3,4|1:5,6', $this->walked);
    }

    public function testRowsRemovedDuringAChunkShiftTheOnesBehindThemOutOfReach(): void
    {
        // The second statement asks for rows 3 and 4 by position. Two rows are
        // gone by then, so the pair that moved into those positions is read and
        // the pair that took their place further down is never seen. This is
        // the behaviour the guide warns about, pinned here so that a change to
        // chunk() that quietly altered it would show up.
        $this->connection->select('id')->from('users')->orderBy('id')
            ->chunk(2, function (Result $batch, int $index): void {
                if ($this->record($batch, $index) === '1,2') {
                    $this->connection->statement('DELETE FROM users WHERE id IN (1, 2)');
                }
            });

        $this->assertSame('|0:1,2|1:5,6', $this->walked);
    }

    public function testRowsRemovedDuringAChunkByIdLeaveTheOnesBehindThemInReach(): void
    {
        // The same removal, but each statement asks for the rows above the
        // value the last one reached, so nothing behind them moves.
        $this->connection->select('id')->from('users')
            ->chunkById(2, function (Result $batch, int $index): void {
                if ($this->record($batch, $index) === '1,2') {
                    $this->connection->statement('DELETE FROM users WHERE id IN (1, 2)');
                }
            });

        $this->assertSame('|0:1,2|1:3,4|2:5,6', $this->walked);
    }

    public function testPaginateReadsThePageAndCountsEveryMatchOnTheServer(): void
    {
        $this->connection->statement('UPDATE users SET status = ? WHERE id IN (2, 4, 6)', ['blocked']);

        $page = $this->connection->select('id')->from('users')->where('status', 'active')
            ->orderBy('id')->paginate(2, 2);

        $this->assertSame('5', self::idsOf($page->items));
        $this->assertSame(3, $page->total);
        $this->assertSame(2, $page->lastPage);
        $this->assertFalse($page->hasMorePages);
        $this->assertSame(1, $page->previousPage);
    }
}
