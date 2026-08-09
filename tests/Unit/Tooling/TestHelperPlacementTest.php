<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TestHelperPlacementTest extends TestCase
{
    private const string TESTS_DIR = __DIR__ . '/../../';

    /**
     * @return list<string>
     */
    private function testFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::TESTS_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getRealPath();
            if ($path !== false) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Report the first private helper declared after the first test method.
     *
     * @param  string      $source File contents
     * @return string|null "line:name" of the offending helper, or null when the file complies
     */
    private function firstLateHelper(string $source): ?string
    {
        $firstTest = null;
        $lines     = explode("\n", $source);

        foreach ($lines as $index => $line) {
            if ($firstTest === null && preg_match('/^    public function test/', $line) === 1) {
                $firstTest = $index;
                continue;
            }
            if ($firstTest === null) {
                continue;
            }
            if (preg_match('/^    private (?:static )?function (\w+)\(/', $line, $matches) === 1) {
                return ($index + 1) . ':' . $matches[1];
            }
        }

        return null;
    }

    public function testPrivateHelpersAreDeclaredBeforeTheFirstTestMethod(): void
    {
        // dev-runbook.md 3-2 requires helpers to sit in front of the test
        // methods. Nothing enforced it: the code-review pass reads a diff, so a
        // helper appended to the end of a file looks local and correct there.
        $offenders = [];
        foreach ($this->testFiles() as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                self::fail('Could not read ' . $path);
            }

            $late = $this->firstLateHelper($source);
            if ($late !== null) {
                $offenders[] = basename($path) . ' L' . $late . '()';
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Move these private helpers above the first test method (shared helpers first, then narrow ones).',
        );
    }

    public function testTheScannerReportsAHelperThatFollowsATestMethod(): void
    {
        // Guards the assertion above against becoming vacuous: a scanner that
        // silently matched nothing would keep the suite green forever.
        $compliant = "class Foo\n{\n    private function helper(): void\n    {\n    }\n\n"
            . "    public function testSomething(): void\n    {\n    }\n}\n";
        $offending = "class Foo\n{\n    public function testSomething(): void\n    {\n    }\n\n"
            . "    private function helper(): void\n    {\n    }\n}\n";

        self::assertNull($this->firstLateHelper($compliant));
        self::assertSame('7:helper', $this->firstLateHelper($offending));
    }
}
