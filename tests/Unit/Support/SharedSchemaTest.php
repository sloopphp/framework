<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Tests\Support\SharedSchema;

final class SharedSchemaTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    /**
     * @param list<string> $statements
     */
    private function firstIndexContaining(array $statements, string $needle): int
    {
        foreach ($statements as $index => $statement) {
            if (str_contains($statement, $needle)) {
                return $index;
            }
        }

        $this->fail('No statement contains ' . $needle . '.');
    }

    /**
     * @param list<string> $statements
     */
    private function lastIndexContaining(array $statements, string $needle): int
    {
        $found = null;

        foreach ($statements as $index => $statement) {
            if (str_contains($statement, $needle)) {
                $found = $index;
            }
        }

        if ($found === null) {
            $this->fail('No statement contains ' . $needle . '.');
        }

        return $found;
    }

    private function writeTemporaryFixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sloop_schema_');

        if ($path === false) {
            $this->fail('Could not create a temporary fixture file.');
        }

        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function nonExistentPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sloop_missing_');

        if ($path === false) {
            $this->fail('Could not reserve a temporary path.');
        }

        // Deleting a name the OS just handed out is the only way to be sure
        // nothing else occupies it.
        unlink($path);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function testEveryParsedStatementStartsWithADdlKeyword(): void
    {
        // Comment residue and a split in the wrong place both show up here as
        // a fragment that does not begin a statement.
        foreach (SharedSchema::statements(SharedSchema::path()) as $statement) {
            $this->assertMatchesRegularExpression('/^(?:DROP|CREATE) TABLE\b/', $statement);
        }
    }

    public function testCommentLinesAreRemoved(): void
    {
        foreach (SharedSchema::statements(SharedSchema::path()) as $statement) {
            $this->assertStringNotContainsString('--', $statement);
            $this->assertStringNotContainsString('Parsing constraint', $statement);
        }
    }

    public function testTablesAreDroppedBeforeTheyAreCreated(): void
    {
        $statements = SharedSchema::statements(SharedSchema::path());

        $firstCreate = $this->firstIndexContaining($statements, 'CREATE TABLE users');
        $lastDrop    = $this->lastIndexContaining($statements, 'DROP TABLE');

        $this->assertLessThan(
            $firstCreate,
            $lastDrop,
            'Every DROP has to run before the first CREATE, otherwise a rerun hits an existing table.',
        );
    }

    public function testTableNamesReportsTheTablesTheFixtureCreates(): void
    {
        $names = SharedSchema::tableNames(SharedSchema::statements(SharedSchema::path()));

        $this->assertSame(['users', 'posts'], $names);
    }

    public function testTableNamesRejectsStatementsThatCreateNothing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('creates a table');

        SharedSchema::tableNames(['DROP TABLE IF EXISTS users']);
    }

    public function testTrailingSemicolonDoesNotProduceAnEmptyStatement(): void
    {
        $path = $this->writeTemporaryFixture("CREATE TABLE a (id INT);\n");

        $this->assertSame(['CREATE TABLE a (id INT)'], SharedSchema::statements($path));
    }

    public function testHashCommentsAreStripped(): void
    {
        // MySQL accepts # as a line comment, and a surviving one would either
        // shift the split or execute as a silent no-op statement.
        $path = $this->writeTemporaryFixture("# a note\nCREATE TABLE a (id INT);\n# trailing note\n");

        $this->assertSame(['CREATE TABLE a (id INT)'], SharedSchema::statements($path));
    }

    public function testBlockCommentsAreStripped(): void
    {
        $path = $this->writeTemporaryFixture("/* a\n   note */\nCREATE TABLE a (id INT);\n");

        $this->assertSame(['CREATE TABLE a (id INT)'], SharedSchema::statements($path));
    }

    public function testSemicolonInsideABlockCommentDoesNotSplitAStatement(): void
    {
        $path = $this->writeTemporaryFixture("CREATE TABLE a (/* id; name */ id INT);\n");

        $this->assertSame(['CREATE TABLE a ( id INT)'], SharedSchema::statements($path));
    }

    public function testFileWithoutStatementsIsRejected(): void
    {
        // Returning an empty list would make load() a no-op that reports
        // success, and the missing tables would surface as an unrelated failure.
        $path = $this->writeTemporaryFixture("-- only a comment\n\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contains no statements');

        SharedSchema::statements($path);
    }

    public function testUnreadableFileIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read the schema fixture');

        SharedSchema::statements($this->nonExistentPath());
    }
}
