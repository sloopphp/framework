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

    private const array DECLARATION_KINDS = [\T_CLASS => 'class', \T_FUNCTION => 'function'];

    private const array VISIBILITY_MODIFIERS = [\T_PUBLIC, \T_PRIVATE, \T_PROTECTED];

    private const array SKIPPED_TOKENS = [\T_WHITESPACE, \T_FINAL, \T_ABSTRACT, \T_STATIC, \T_READONLY, \T_COMMENT];

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
     * Find the token that closes an attribute.
     *
     * T_ATTRIBUTE is only the opening '#[', so the name, its arguments and the
     * closing bracket are separate tokens that a plain skip list walks into.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens Full token stream
     * @param  int                                      $index  Offset of the T_ATTRIBUTE
     * @return int                                      Offset of the matching ']', or of the
     *                                                  last token when the stream ends first
     */
    private function attributeEnd(array $tokens, int $index): int
    {
        $depth = 1;
        $count = \count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '[' || (\is_array($token) && $token[0] === \T_ATTRIBUTE)) {
                $depth++;
                continue;
            }
            if ($token === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Name the declaration a docblock is attached to, skipping modifiers.
     *
     * Returns null when the docblock precedes something else (a local variable
     * annotation, a closure, a constant). isPublic reports the declared
     * visibility, which defaults to public when no modifier is written.
     *
     * @param  list<array{0:int,1:string,2:int}|string>          $tokens Full token stream
     * @param  int                                               $index  Offset of the docblock
     * @return array{kind:string,name:string,isPublic:bool}|null
     */
    private function declarationAfter(array $tokens, int $index): ?array
    {
        [$cursor, $isPublic] = $this->skipModifiers($tokens, $index + 1, true);

        $token = $tokens[$cursor] ?? null;
        $kind  = \is_array($token) ? (self::DECLARATION_KINDS[$token[0]] ?? null) : null;
        if ($kind === null) {
            return null;
        }

        [$cursor, $isPublic] = $this->skipModifiers($tokens, $cursor + 1, $isPublic);

        $token = $tokens[$cursor] ?? null;
        if (!\is_array($token) || $token[0] !== \T_STRING) {
            return null;
        }

        return ['kind' => $kind, 'name' => $token[1], 'isPublic' => $isPublic];
    }

    /**
     * Walk past attributes, modifiers, whitespace and comments.
     *
     * A visibility modifier on the way updates isPublic; the last one seen wins.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens   Full token stream
     * @param  int                                      $start    Offset to start walking from
     * @param  bool                                     $isPublic Visibility carried in from earlier tokens
     * @return array{0:int,1:bool}                      Offset of the first other token (the token
     *                                                  count when none remains) and the visibility
     */
    private function skipModifiers(array $tokens, int $start, bool $isPublic): array
    {
        $count = \count($tokens);
        $i     = $start;

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                break;
            }
            if ($token[0] === \T_ATTRIBUTE) {
                $i = $this->attributeEnd($tokens, $i);
                continue;
            }
            if (\in_array($token[0], self::VISIBILITY_MODIFIERS, true)) {
                $isPublic = $token[0] === \T_PUBLIC;
                continue;
            }
            if (!\in_array($token[0], self::SKIPPED_TOKENS, true)) {
                break;
            }
        }

        return [$i, $isPublic];
    }

    /**
     * Report the convention violations in one test file.
     *
     * Three shapes are checked: a docblock on the test class, one on
     * setUp/tearDown, and a prose docblock on a public test method. A docblock
     * attached to anything else is out of scope.
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

            $violation = $this->violationOf($declaration, $token[1]);
            if ($violation !== null) {
                $found[] = $token[2] . ': ' . $violation;
            }
        }

        return $found;
    }

    /**
     * Report the convention a docblock breaks, given what it is attached to.
     *
     * @param  array{kind:string,name:string,isPublic:bool} $declaration Declaration the docblock precedes
     * @param  string                                       $docblock    Raw T_DOC_COMMENT text
     * @return string|null                                  Reason, or null when the docblock is allowed
     */
    private function violationOf(array $declaration, string $docblock): ?string
    {
        $name = $declaration['name'];

        if ($declaration['kind'] === 'class') {
            return str_ends_with($name, 'Test') ? 'class ' . $name . ' carries a docblock' : null;
        }
        if ($name === 'setUp' || $name === 'tearDown') {
            return $name . '() carries a docblock';
        }
        if ($declaration['isPublic'] && str_starts_with($name, 'test') && $this->carriesProse($docblock)) {
            return $name . '() carries a prose docblock';
        }

        return null;
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
        $offending = "<?php\n/**\n * Why this class exists.\n */\n#[CoversClass(Foo::class)]\nfinal class FooTest\n{\n"
            . "    /**\n     * Prepare.\n     */\n    protected function setUp(): void\n    {\n    }\n\n"
            . "    /**\n     * Clean up.\n     */\n    protected function tearDown(): void\n    {\n    }\n\n"
            . "    /**\n     * Explains the case.\n     */\n    public function testThing(): void\n    {\n    }\n\n"
            . "    /**\n     * Explains the provided case.\n     *\n     * @param string \$value\n     */\n"
            . "    #[DataProvider('cases')]\n    public function testProvided(string \$value): void\n    {\n    }\n\n"
            . "    /**\n     * Explains the implicit case.\n     */\n    function testImplicit(): void\n    {\n    }\n\n"
            . "    /**\n     * Explains the table case.\n     */\n"
            . "    #[TestWith([1, 2])]\n    public function testTable(int \$a, int \$b): void\n    {\n    }\n}\n";

        self::assertSame(
            [
                '2: class FooTest carries a docblock',
                '8: setUp() carries a docblock',
                '15: tearDown() carries a docblock',
                '22: testThing() carries a prose docblock',
                '29: testProvided() carries a prose docblock',
                '39: testImplicit() carries a prose docblock',
                '46: testTable() carries a prose docblock',
            ],
            $this->violations($offending),
        );

        $exempt = "<?php\nfinal class FooTest\n{\n"
            . "    /**\n     * @return list<string>\n     */\n"
            . "    public static function cases(): array\n    {\n        return [];\n    }\n\n"
            . "    /**\n     * @param string \$value\n     */\n"
            . "    #[DataProvider('cases')]\n    public function testProvided(string \$value): void\n    {\n    }\n\n"
            . "    /**\n     * @param int \$a\n     */\n"
            . "    #[TestWith([1, 2])]\n    public function testTable(int \$a): void\n    {\n    }\n\n"
            . "    /**\n     * Why this fixture is shaped this way.\n     */\n"
            . "    private function testDouble(): void\n    {\n    }\n}\n\n"
            . "/**\n * A stub the test drives.\n */\nfinal class FooStub\n{\n"
            . "    /**\n     * Why the stub behaves this way.\n     */\n"
            . "    private function helper(): void\n    {\n    }\n}\n";

        self::assertSame([], $this->violations($exempt));
    }
}
