<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TestDocblockConventionTest extends TestCase
{
    private const string TESTS_DIR = __DIR__ . '/../../';

    /**
     * @return list<string>
     */
    private function testClassFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::TESTS_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), 'Test.php')) {
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
     * Report whether a docblock carries anything other than tags.
     *
     * @param  string $docblock Raw T_DOC_COMMENT text
     * @return bool   True when a line is neither blank nor a tag line
     */
    private function carriesProse(string $docblock): bool
    {
        foreach (explode("\n", $docblock) as $line) {
            $line = trim($line, " \t*/");
            if ($line !== '' && !str_starts_with($line, '@')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Name the declaration a docblock is attached to, skipping modifiers.
     *
     * Returns 'class Foo', 'function bar' or null when the docblock precedes
     * something else (a local variable annotation, a closure, a constant).
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens Full token stream
     * @param  int                                      $index  Offset of the docblock
     * @return string|null                              "kind name", or null
     */
    private function declarationAfter(array $tokens, int $index): ?string
    {
        $skip = [
            \T_WHITESPACE, \T_ATTRIBUTE, \T_FINAL, \T_ABSTRACT, \T_STATIC,
            \T_PUBLIC, \T_PRIVATE, \T_PROTECTED, \T_READONLY, \T_COMMENT,
        ];

        $kind  = null;
        $count = \count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_array($token) && \in_array($token[0], $skip, true)) {
                continue;
            }
            if ($kind === null) {
                if (!\is_array($token)) {
                    return null;
                }
                if ($token[0] === \T_CLASS) {
                    $kind = 'class';
                    continue;
                }
                if ($token[0] === \T_FUNCTION) {
                    $kind = 'function';
                    continue;
                }

                return null;
            }
            if (\is_array($token) && $token[0] === \T_STRING) {
                return $kind . ' ' . $token[1];
            }

            return null;
        }

        return null;
    }

    /**
     * Report the convention violations in one test file.
     *
     * The convention lives in .claude/CLAUDE.md: a test class, its fixtures and
     * its test methods carry no prose docblock, because the method name already
     * states the condition and the expected result. Private helpers and stub
     * classes are exempt -- those explain why a fixture is built the way it is,
     * which the name cannot carry.
     *
     * @param  string       $source File contents
     * @return list<string> "line: reason" for each violation
     */
    private function violations(string $source): array
    {
        $tokens = token_get_all($source);
        $found  = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {
                continue;
            }

            $declaration = $this->declarationAfter($tokens, $index);
            if ($declaration === null) {
                continue;
            }

            [$kind, $name] = explode(' ', $declaration, 2);
            $line          = $token[2];

            if ($kind === 'class' && str_ends_with($name, 'Test')) {
                $found[] = $line . ': class ' . $name . ' carries a docblock';
                continue;
            }
            if ($kind !== 'function') {
                continue;
            }
            if ($name === 'setUp' || $name === 'tearDown') {
                $found[] = $line . ': ' . $name . '() carries a docblock';
                continue;
            }
            if (str_starts_with($name, 'test') && $this->carriesProse($token[1])) {
                $found[] = $line . ': ' . $name . '() carries a prose docblock';
            }
        }

        return $found;
    }

    public function testTestClassesAndTestMethodsCarryNoProseDocblock(): void
    {
        // A reviewer caught this on PR #56 after a full review pass. The rule was
        // already written down, but nothing read it before the reviewer did, so
        // the round cost a review cycle instead of a few seconds here. PHPCS does
        // not reach tests/, which is why the check lives as a test.
        $offenders = [];
        foreach ($this->testClassFiles() as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                self::fail('Could not read ' . $path);
            }

            foreach ($this->violations($source) as $violation) {
                $offenders[] = basename($path) . ' L' . $violation;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Drop these docblocks. Test names state the condition and the expected result; '
            . 'tag-only blocks (@param / @return for data providers) stay.',
        );
    }

    public function testTheScannerReportsEachViolationShapeAndSparesTheExemptOnes(): void
    {
        // Guards the assertion above against becoming vacuous, and pins the
        // exemptions so a later tightening cannot quietly swallow them.
        $offending = "<?php\n/**\n * Why this class exists.\n */\nfinal class FooTest\n{\n"
            . "    /**\n     * Prepare.\n     */\n    protected function setUp(): void\n    {\n    }\n\n"
            . "    /**\n     * Explains the case.\n     */\n    public function testThing(): void\n    {\n    }\n}\n";

        self::assertSame(
            [
                '2: class FooTest carries a docblock',
                '7: setUp() carries a docblock',
                '14: testThing() carries a prose docblock',
            ],
            $this->violations($offending),
        );

        $exempt = "<?php\nfinal class FooTest\n{\n"
            . "    /**\n     * @return list<string>\n     */\n"
            . "    public static function cases(): array\n    {\n        return [];\n    }\n\n"
            . "    /**\n     * @param string \$value\n     */\n"
            . "    public function testThing(string \$value): void\n    {\n    }\n}\n\n"
            . "/**\n * A stub the test drives.\n */\nfinal class FooStub\n{\n"
            . "    /**\n     * Why the stub behaves this way.\n     */\n"
            . "    private function helper(): void\n    {\n    }\n}\n";

        self::assertSame([], $this->violations($exempt));
    }
}
