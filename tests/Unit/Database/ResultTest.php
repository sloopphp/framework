<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Database\Result;
use Sloop\Support\Collection;
use Sloop\Tests\Unit\Database\Stub\HydratedAbstract;
use Sloop\Tests\Unit\Database\Stub\HydratedNullable;
use Sloop\Tests\Unit\Database\Stub\HydratedSparse;
use Sloop\Tests\Unit\Database\Stub\HydratedUser;
use Sloop\Tests\Unit\Database\Stub\HydratedVariadic;
use Sloop\Tests\Unit\Database\Stub\HydratedWithoutConstructor;
use TypeError;

final class ResultTest extends TestCase
{
    public function testCountReturnsNumberOfRows(): void
    {
        $result = new Result([
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
            ['id' => 3, 'name' => 'carol'],
        ]);

        $this->assertCount(3, $result);
    }

    public function testCountReturnsZeroForEmptyResult(): void
    {
        $this->assertCount(0, new Result([]));
    }

    public function testIteratesRowsInInsertionOrder(): void
    {
        $rows   = [
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
        ];
        $result = new Result($rows);

        $collected = [];
        foreach ($result as $row) {
            $collected[] = $row;
        }

        $this->assertSame($rows, $collected);
    }

    public function testIteratingEmptyResultYieldsNothing(): void
    {
        $collected = [];
        foreach (new Result([]) as $row) {
            $collected[] = $row;
        }

        $this->assertSame([], $collected);
    }

    public function testAsArrayReturnsSameRows(): void
    {
        $rows   = [['id' => 1, 'name' => 'alice']];
        $result = new Result($rows);

        $this->assertSame($rows, $result->asArray());
    }

    public function testAsArrayReturnsEmptyArrayForEmptyResult(): void
    {
        $this->assertSame([], new Result([])->asArray());
    }

    public function testCanBeIteratedMultipleTimes(): void
    {
        $rows   = [['id' => 1], ['id' => 2]];
        $result = new Result($rows);

        $first  = iterator_to_array($result);
        $second = iterator_to_array($result);

        $this->assertSame($rows, $first);
        $this->assertSame($rows, $second);
    }

    public function testIsEmptyReportsWhetherThereAreRows(): void
    {
        $this->assertTrue(new Result([])->isEmpty());
        $this->assertFalse(new Result([['id' => 1]])->isEmpty());
    }

    public function testFirstReturnsTheLeadingRow(): void
    {
        $result = new Result([['id' => 1], ['id' => 2]]);

        $this->assertSame(['id' => 1], $result->first());
    }

    public function testFirstReturnsNullForEmptyResult(): void
    {
        $this->assertNull(new Result([])->first());
    }

    public function testAsArrayByKeysRowsByTheGivenColumn(): void
    {
        $result = new Result([
            ['id' => 7, 'name' => 'alice'],
            ['id' => 9, 'name' => 'bob'],
        ]);

        $this->assertSame(
            [
                7 => ['id' => 7, 'name' => 'alice'],
                9 => ['id' => 9, 'name' => 'bob'],
            ],
            $result->asArrayBy('id'),
        );
    }

    public function testAsArrayByKeepsTheLastRowOnDuplicateKeys(): void
    {
        $result = new Result([
            ['id' => 1, 'name' => 'first'],
            ['id' => 1, 'name' => 'second'],
        ]);

        $this->assertSame([1 => ['id' => 1, 'name' => 'second']], $result->asArrayBy('id'));
    }

    public function testAsArrayByDropsTheKeyColumnWhenAsked(): void
    {
        $result = new Result([['id' => 7, 'name' => 'alice']]);

        $this->assertSame([7 => ['name' => 'alice']], $result->asArrayBy('id', removeKey: true));
    }

    public function testAsArrayByRejectsAColumnThatIsNotInTheResult(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Column "missing" is not in the result set.');

        $result->asArrayBy('missing');
    }

    public function testAsArrayByRejectsANullKeyValue(): void
    {
        // PHP would turn a null key into '' and silently merge every such row
        // into one entry.
        $result = new Result([['id' => null, 'name' => 'alice']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column "id" must hold an int or string to be used as a key, got null.',
        );

        $result->asArrayBy('id');
    }

    public function testAsArrayByRejectsAFloatKeyValue(): void
    {
        // A float key is truncated to int, so 1.5 and 1.9 would collide on 1.
        $result = new Result([['id' => 1.5, 'name' => 'alice']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Column "id" must hold an int or string to be used as a key, got float.',
        );

        $result->asArrayBy('id');
    }

    public function testAsMapPairsTwoColumns(): void
    {
        $result = new Result([
            ['id' => 1, 'email' => 'alice@example.com'],
            ['id' => 2, 'email' => 'bob@example.com'],
        ]);

        $this->assertSame(
            [1 => 'alice@example.com', 2 => 'bob@example.com'],
            $result->asMap('id', 'email'),
        );
    }

    public function testAsMapCarriesNullValuesThrough(): void
    {
        $result = new Result([['id' => 1, 'deleted_at' => null]]);

        $this->assertSame([1 => null], $result->asMap('id', 'deleted_at'));
    }

    public function testAsMapKeepsTheLastValueOnDuplicateKeys(): void
    {
        $result = new Result([
            ['id' => 1, 'email' => 'first@example.com'],
            ['id' => 1, 'email' => 'second@example.com'],
        ]);

        $this->assertSame([1 => 'second@example.com'], $result->asMap('id', 'email'));
    }

    public function testAsMapRejectsAValueColumnThatIsNotInTheResult(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Column "email" is not in the result set.');

        $result->asMap('id', 'email');
    }

    public function testGroupByCollectsRowsSharingAColumnValue(): void
    {
        $result = new Result([
            ['status' => 'active', 'id' => 1],
            ['status' => 'banned', 'id' => 2],
            ['status' => 'active', 'id' => 3],
        ]);

        $this->assertSame(
            [
                'active' => [
                    ['status' => 'active', 'id' => 1],
                    ['status' => 'active', 'id' => 3],
                ],
                'banned' => [
                    ['status' => 'banned', 'id' => 2],
                ],
            ],
            $result->groupBy('status'),
        );
    }

    public function testGroupByRejectsAColumnThatIsNotInTheResult(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Column "status" is not in the result set.');

        $result->groupBy('status');
    }

    public function testToCollectionHandsTheRowsToACollection(): void
    {
        $rows   = [['id' => 1, 'score' => 10], ['id' => 2, 'score' => 32]];
        $result = new Result($rows);

        $collection = $result->toCollection();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame($rows, $collection->toArray());
        // Exercising a Collection-only operation proves the bridge produces a
        // usable Collection rather than something that merely holds the rows.
        $this->assertSame(42, $collection->sum('score'));
    }

    public function testAsObjectHydratesOneInstancePerRowInOrder(): void
    {
        $result = new Result([
            ['id' => 1, 'name' => 'alice', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'bob', 'email' => 'bob@example.com'],
        ]);

        $users = $result->asObject(HydratedUser::class);

        $this->assertCount(2, $users);
        $this->assertSame([1, 2], array_map(static fn (HydratedUser $u): int => $u->id, $users));
        $this->assertSame('alice@example.com', $users[0]->email);
    }

    public function testAsObjectReadsEachColumnIntoTheParameterOfTheSameName(): void
    {
        $result = new Result([['email' => 'z@example.com', 'name' => 'zoe', 'id' => 9]]);

        $user = $result->asObject(HydratedUser::class)[0];

        $this->assertSame(9, $user->id);
        $this->assertSame('zoe', $user->name);
        $this->assertSame('z@example.com', $user->email);
    }

    public function testAsObjectIgnoresColumnsThatNoParameterTakes(): void
    {
        $result = new Result([['id' => 1, 'name' => 'alice', 'unused' => 'x']]);

        $this->assertSame(1, $result->asObject(HydratedUser::class)[0]->id);
    }

    public function testAsObjectLeavesAnAbsentOptionalColumnToItsDefault(): void
    {
        $result = new Result([['id' => 1, 'name' => 'alice']]);

        $this->assertNull($result->asObject(HydratedUser::class)[0]->email);
    }

    public function testAsObjectSkipsAnAbsentOptionalColumnWithoutShiftingTheOnesAfterIt(): void
    {
        $result = new Result([['id' => 1, 'rank' => 5]]);

        $sparse = $result->asObject(HydratedSparse::class)[0];

        $this->assertSame('none', $sparse->label);
        $this->assertSame(5, $sparse->rank);
    }

    public function testAsObjectPassesANullColumnThroughRatherThanFallingBackToTheDefault(): void
    {
        $result = new Result([['id' => 1, 'note' => null]]);

        $this->assertNull($result->asObject(HydratedNullable::class)[0]->note);
    }

    public function testAsObjectUsesTheDefaultOnlyWhenTheColumnIsAbsent(): void
    {
        $result = new Result([['id' => 1]]);

        $this->assertSame('unset', $result->asObject(HydratedNullable::class)[0]->note);
    }

    public function testAsObjectRejectsARowMissingAColumnARequiredParameterNeeds(): void
    {
        $result = new Result([['id' => 1, 'name' => 'alice'], ['id' => 2]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Row 1 has no column "name" for ' . HydratedUser::class
                . '::__construct(). Columns present: id.',
        );

        $result->asObject(HydratedUser::class);
    }

    public function testAsObjectReturnsAnEmptyListForAnEmptyResult(): void
    {
        $this->assertSame([], new Result([])->asObject(HydratedUser::class));
    }

    public function testAsObjectRejectsAnUnusableClassEvenWithNoRowsToHydrate(): void
    {
        $result = new Result([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Constructor of "' . HydratedVariadic::class
                . '" takes a variadic parameter ($columns), which columns cannot be matched to.',
        );

        $result->asObject(HydratedVariadic::class);
    }

    public function testAsObjectRejectsAClassThatDoesNotExist(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Class "Sloop\\Tests\\Unit\\Database\\Stub\\NoSuchDto" does not exist.',
        );

        /** @phpstan-ignore argument.type */
        $result->asObject('Sloop\\Tests\\Unit\\Database\\Stub\\NoSuchDto');
    }

    public function testAsObjectRejectsAClassThatCannotBeInstantiated(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Class "' . HydratedAbstract::class
                . '" cannot be instantiated, so rows cannot be hydrated into it.',
        );

        $result->asObject(HydratedAbstract::class);
    }

    public function testAsObjectRejectsAClassWithoutAConstructor(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Class "' . HydratedWithoutConstructor::class
                . '" has no constructor, so there is nowhere to pass the columns.',
        );

        $result->asObject(HydratedWithoutConstructor::class);
    }

    public function testAsObjectRejectsAVariadicConstructorBeforeReadingAnyRow(): void
    {
        $result = new Result([['id' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Constructor of "' . HydratedVariadic::class
                . '" takes a variadic parameter ($columns), which columns cannot be matched to.',
        );

        $result->asObject(HydratedVariadic::class);
    }

    public function testAsObjectLetsTheConstructorsTypeErrorThroughNamingTheParameter(): void
    {
        $result = new Result([['id' => 'not-an-int', 'name' => 'alice']]);

        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/\$id/');

        $result->asObject(HydratedUser::class);
    }
}
