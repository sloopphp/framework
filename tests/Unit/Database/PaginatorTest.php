<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Paginator;
use Sloop\Database\Result;
use Sloop\Tests\Support\ThrowsAssertions;

final class PaginatorTest extends TestCase
{
    use ThrowsAssertions;

    private function paginator(int $total, int $perPage, int $currentPage): Paginator
    {
        return new Paginator(new Result([]), $total, $perPage, $currentPage);
    }

    public function testTheItemsAndCountsAreHeldAsGiven(): void
    {
        $items = new Result([['id' => 1], ['id' => 2]]);

        $page = new Paginator($items, 25, 10, 2);

        $this->assertSame($items, $page->items);
        $this->assertSame(25, $page->total);
        $this->assertSame(10, $page->perPage);
        $this->assertSame(2, $page->currentPage);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function provideTotalsAndTheirLastPage(): array
    {
        return [
            'a partial page still counts'   => [25, 10, 3],
            'an exact fit adds no page'     => [20, 10, 2],
            'one row is one page'           => [1, 10, 1],
            'one row over is two pages'     => [11, 10, 2],
            'nothing matched is still page 1' => [0, 10, 1],
        ];
    }

    #[DataProvider('provideTotalsAndTheirLastPage')]
    public function testTheLastPageIsTheTotalCutIntoPages(int $total, int $perPage, int $expected): void
    {
        $this->assertSame($expected, $this->paginator($total, $perPage, 1)->lastPage);
    }

    public function testAPageBeforeTheLastHasOneAfterIt(): void
    {
        $page = $this->paginator(25, 10, 2);

        $this->assertTrue($page->hasMorePages);
        $this->assertSame(3, $page->nextPage);
    }

    public function testTheLastPageHasNothingAfterIt(): void
    {
        $page = $this->paginator(25, 10, 3);

        $this->assertFalse($page->hasMorePages);
        $this->assertNull($page->nextPage);
    }

    public function testTheFirstPageHasNothingBeforeIt(): void
    {
        $this->assertNull($this->paginator(25, 10, 1)->previousPage);
    }

    public function testAPageAfterTheFirstHasOneBeforeIt(): void
    {
        $this->assertSame(1, $this->paginator(25, 10, 2)->previousPage);
    }

    public function testANumberPastTheLastPageEndsRatherThanRunningOn(): void
    {
        // Stepping forward from beyond the set has to stop; stepping back has
        // to still work, so that a caller handed a stale number can recover.
        $page = $this->paginator(25, 10, 9);

        $this->assertFalse($page->hasMorePages);
        $this->assertNull($page->nextPage);
        $this->assertSame(8, $page->previousPage);
    }

    public function testAnEmptySetIsOnePageWithNothingAroundIt(): void
    {
        $page = $this->paginator(0, 10, 1);

        $this->assertSame(1, $page->lastPage);
        $this->assertFalse($page->hasMorePages);
        $this->assertNull($page->nextPage);
        $this->assertNull($page->previousPage);
    }

    /**
     * @return array<string, array{int, int, int, string}>
     */
    public static function provideCountsThatCannotDescribeAPage(): array
    {
        return [
            'a negative total'   => [-1, 10, 1, 'Total must not be negative, got -1.'],
            'no rows per page'   => [10, 0, 1, 'Rows per page must be at least 1, got 0.'],
            'a page below one'   => [10, 10, 0, 'Page number must be at least 1, got 0.'],
        ];
    }

    #[DataProvider('provideCountsThatCannotDescribeAPage')]
    public function testCountsThatCannotDescribeAPageAreRefused(
        int $total,
        int $perPage,
        int $currentPage,
        string $expectedMessage,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        new Paginator(new Result([]), $total, $perPage, $currentPage);
    }
}
