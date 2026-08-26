<?php

declare(strict_types=1);

/*
 * Compares the surviving mutants of the latest Infection run against a committed
 * baseline and fails when a mutant survives that was not surviving before.
 *
 * Why a baseline instead of only minMsi: MSI is an aggregate. Adding well
 * tested code raises it, so a newly escaping mutant in an existing file can be
 * masked by unrelated growth. The baseline is per mutant, so a regression is
 * visible regardless of what else changed.
 *
 * The identity of a mutant is (relative path, enclosing method, mutator name,
 * changed diff lines). Line numbers are deliberately excluded: editing anything
 * above a mutant would otherwise invalidate every entry below it.
 *
 * Escaped, timeouted and errored mutants are all compared against the baseline.
 * None of the three had an assertion detect the mutation, yet MSI counts
 * timeouts and errors in its numerator, so a mutant that hangs or crashes the
 * runner scores as detected without a test having said so. Requiring each one
 * to be in the baseline gives them the review the escaped ones already get.
 *
 * Which of timeouted and errored a mutant lands on is decided by the timeout
 * setting rather than by machine load. At timeout 30 the two MiddlewareDispatcher
 * mutants exhaust the stack and report as errored; at timeout 0.5 the timeout
 * fires first and the same two report as timeouted. Measured over 13 runs on
 * 2026-08-27: at timeout 30 the three sets were identical every time, including
 * on a saturated machine that slowed a run by 2.38x. The slowest killed mutant
 * needs about 0.4s of wall time, leaving a 75x margin to the timeout.
 *
 * Skipped mutants fail the run outright. Infection marks a mutant skipped when
 * its nominal test time already exceeds the timeout, so the mutant is never
 * executed, and MSI removes it from the denominator instead of counting it
 * against the score. The report carries only a count of them and no list, so
 * they cannot be baselined the way the other three are.
 *
 * The baseline is read from the committed file, never from build/. A stale
 * generated report was once mistaken for the current baseline, which made a
 * pre-existing escape look like a new regression.
 *
 * Usage:
 *   .claude/mutation-baseline.php             # check the latest run (exit 1 on regression)
 *   .claude/mutation-baseline.php --update    # rewrite the baseline from the latest run
 *
 * Run Infection first; this script only reads its JSON report.
 */

namespace Sloop\Tooling;

/**
 * One surviving mutant, identified independently of its line number.
 */
final readonly class Mutant
{
    /**
     * Build a mutant record.
     *
     * @param string $file    Source path relative to the repository root
     * @param string $method  Enclosing scope such as ClassName::methodName, empty when unresolved
     * @param string $mutator Infection mutator name
     * @param string $diff    Added and removed lines of the mutation, newline separated
     */
    public function __construct(
        public string $file,
        public string $method,
        public string $mutator,
        public string $diff,
    ) {
    }

    /**
     * Build a mutant from one entry of an Infection JSON report.
     *
     * @param  array<array-key, mixed> $entry   Single result entry
     * @param  string                  $rootDir Repository root, stripped from the reported path
     * @return self|null               Mutant, or null when the entry carries no mutator
     */
    public static function fromReportEntry(array $entry, string $rootDir): ?self
    {
        $mutator = Json::asArray($entry['mutator'] ?? null);
        if ($mutator === []) {
            return null;
        }

        $absolutePath = Json::asString($mutator['originalFilePath'] ?? null);
        $prefix       = $rootDir . '/';

        return new self(
            str_starts_with($absolutePath, $prefix) ? substr($absolutePath, \strlen($prefix)) : $absolutePath,
            ScopeIndex::resolve($absolutePath, Json::asInt($mutator['originalStartLine'] ?? null)),
            Json::asString($mutator['mutatorName'] ?? null),
            self::changedLines(Json::asString($entry['diff'] ?? null)),
        );
    }

    /**
     * Build a mutant from one entry of the committed baseline.
     *
     * @param  array<array-key, mixed> $entry Single baseline entry
     * @return self                    Mutant with missing fields treated as empty
     */
    public static function fromBaselineEntry(array $entry): self
    {
        return new self(
            Json::asString($entry['file'] ?? null),
            Json::asString($entry['method'] ?? null),
            Json::asString($entry['mutator'] ?? null),
            Json::asString($entry['diff'] ?? null),
        );
    }

    /**
     * Return the identity used to match a mutant across runs.
     *
     * @return string Key that stays stable across unrelated edits
     */
    public function key(): string
    {
        return $this->file . "\0" . $this->method . "\0" . $this->mutator . "\0" . $this->diff;
    }

    /**
     * Render the mutant for an operator reading the failure output.
     *
     * @return string Multi line description
     */
    public function describe(): string
    {
        $where = $this->method === '' ? $this->file : $this->file . ' ' . $this->method . '()';

        return '  ' . $where . ' [' . $this->mutator . ']' . \PHP_EOL
            . '    ' . str_replace("\n", \PHP_EOL . '    ', $this->diff);
    }

    /**
     * Return the mutant as it is stored in the baseline file.
     *
     * @return array{file: string, method: string, mutator: string, diff: string} Serialisable form
     */
    public function toArray(): array
    {
        return [
            'file'    => $this->file,
            'method'  => $this->method,
            'mutator' => $this->mutator,
            'diff'    => $this->diff,
        ];
    }

    /**
     * Keep only the added and removed lines of a diff.
     *
     * Context lines are dropped so that edits around a mutant do not change the
     * identity. File headers carry no mutation information.
     *
     * @param  string $diff Diff as reported by Infection
     * @return string Changed lines joined by newlines
     */
    private static function changedLines(string $diff): string
    {
        $changed = [];
        foreach (explode("\n", $diff) as $line) {
            if ($line === '' || ($line[0] !== '-' && $line[0] !== '+')) {
                continue;
            }

            if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                continue;
            }

            $changed[] = rtrim($line);
        }

        return implode("\n", $changed);
    }
}

/**
 * Narrowing helpers for values decoded from JSON.
 *
 * Infection's report and the baseline file are external input, so every field
 * arrives as mixed. Narrowing here keeps casts out of the calling code.
 */
final class Json
{
    /**
     * Read and decode a JSON file, exiting with a diagnostic when it cannot be used.
     *
     * @param  string                  $path Absolute path to the JSON file
     * @param  string                  $hint Shown to the operator when the file is missing
     * @return array<array-key, mixed> Decoded top level object
     * @throws \RuntimeException       When the file is missing, unreadable or not a JSON object
     */
    public static function readFile(string $path, string $hint): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('not found: ' . $path . \PHP_EOL . $hint);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('cannot read: ' . $path);
        }

        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('invalid JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException('expected a JSON object in ' . $path);
        }

        return $decoded;
    }

    /**
     * Narrow a decoded value to a string.
     *
     * @param  mixed  $value Decoded JSON value
     * @return string The value when it is a string, an empty string otherwise
     */
    public static function asString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * Narrow a decoded value to an integer.
     *
     * @param  mixed $value Decoded JSON value
     * @return int   The value when it is an integer, zero otherwise
     */
    public static function asInt(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }

    /**
     * Narrow a decoded value to an array.
     *
     * @param  mixed                   $value Decoded JSON value
     * @return array<array-key, mixed> The value when it is an array, an empty array otherwise
     */
    public static function asArray(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}

/**
 * Tracks which class-like declaration a token sits in while scanning a file.
 *
 * Names are remembered with the brace depth they were opened at, so a method
 * that follows a nested class is still attributed to the enclosing one.
 * Anonymous classes contribute no name, so their methods are reported under
 * the class that contains them; that is imprecise but stable, which is what
 * the identity of a mutant needs.
 */
final class BraceTracker
{
    /**
     * Current brace nesting depth.
     *
     * @var int
     */
    private int $depth = 0;

    /**
     * Open class-like scopes as name and the depth they were declared at.
     *
     * @var list<array{0: string, 1: int}>
     */
    private array $scopes = [];

    /**
     * Update the depth for a single character token.
     *
     * @param  string $token Character token from the stream
     * @return void
     */
    public function advance(string $token): void
    {
        if ($token === '{') {
            $this->depth++;

            return;
        }

        if ($token !== '}') {
            return;
        }

        $this->depth--;

        $innermost = end($this->scopes);
        if ($innermost !== false && $innermost[1] >= $this->depth) {
            array_pop($this->scopes);
        }
    }

    /**
     * Record a class-like declaration opening at the current depth.
     *
     * @param  string|null $name Declared name, or null for an anonymous class
     * @return void
     */
    public function openScope(?string $name): void
    {
        if ($name === null) {
            return;
        }

        $this->scopes[] = [$name, $this->depth];
    }

    /**
     * Return the innermost class-like scope.
     *
     * @return string Scope name, empty at file level
     */
    public function currentScope(): string
    {
        $innermost = end($this->scopes);

        return $innermost === false ? '' : $innermost[0];
    }
}

/**
 * Maps line numbers to the method that encloses them.
 *
 * Infection reports only a file and a start line, so the enclosing method is
 * resolved by tokenising the source. Tokenising rather than reflection keeps
 * this free of autoloading and side effects.
 */
final class ScopeIndex
{
    /**
     * Function spans per file, keyed by path and file identity.
     *
     * Each span is a start line, an end line and the scope name. Files are
     * tokenised once because a report repeats the same file many times. The
     * modification time and size are part of the key so that a rewritten file
     * is re-read rather than answered from a stale entry.
     *
     * @var array<string, list<array{0: int, 1: int, 2: string}>>
     */
    private static array $cache = [];

    /**
     * Return the method that encloses a line.
     *
     * @param  string $file Absolute path to the PHP source file
     * @param  int    $line 1 based line number of the mutated statement
     * @return string Enclosing scope such as ClassName::methodName, empty when unresolved
     */
    public static function resolve(string $file, int $line): string
    {
        $readable = is_file($file);
        $key      = $readable
            ? $file . "\0" . (string) filemtime($file) . "\0" . (string) filesize($file)
            : $file;

        if (!\array_key_exists($key, self::$cache)) {
            self::$cache[$key] = $readable ? self::collect($file) : [];
        }

        $best     = '';
        $bestSpan = \PHP_INT_MAX;
        foreach (self::$cache[$key] as [$start, $end, $name]) {
            if ($line < $start || $line > $end) {
                continue;
            }

            // Nested functions overlap; the tightest enclosing span is the answer.
            $span = $end - $start;
            if ($span < $bestSpan) {
                $best     = $name;
                $bestSpan = $span;
            }
        }

        return $best;
    }

    /**
     * Collect every function body in a file with the lines it spans.
     *
     * @param  string                                 $file Absolute path to the PHP source file
     * @return list<array{0: int, 1: int, 2: string}> Start line, end line and scope name
     */
    private static function collect(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        $tokens = token_get_all($contents);
        $count  = \count($tokens);
        $scopes = [];
        $braces = new BraceTracker();

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (\is_string($token)) {
                $braces->advance($token);

                continue;
            }

            if (self::opensInterpolation($token[0])) {
                // The matching close is a plain '}' token, so this opener has
                // to be counted or the brace depth drifts.
                $braces->advance('{');

                continue;
            }

            if (self::isClassLike($token[0])) {
                $braces->openScope(self::declaredName($tokens, $index, $count));

                continue;
            }

            if ($token[0] !== \T_FUNCTION) {
                continue;
            }

            $scope = self::functionScope($tokens, $index, $count, $braces->currentScope());
            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * Report whether a token opens a class-like declaration.
     *
     * @param  int  $tokenType Token type constant
     * @return bool True for class, interface, trait and enum
     */
    private static function isClassLike(int $tokenType): bool
    {
        return $tokenType === \T_CLASS
            || $tokenType === \T_INTERFACE
            || $tokenType === \T_TRAIT
            || $tokenType === \T_ENUM;
    }

    /**
     * Report whether a token opens a brace that the tokenizer closes with a plain '}'.
     *
     * String interpolation and heredocs emit a dedicated opening token but an
     * ordinary '}' to close, so counting only plain braces drifts the depth and
     * pops the enclosing class off the stack.
     *
     * @param  int  $tokenType Token type constant
     * @return bool True for the interpolation openers
     */
    private static function opensInterpolation(int $tokenType): bool
    {
        return $tokenType === \T_CURLY_OPEN
            || $tokenType === \T_DOLLAR_OPEN_CURLY_BRACES;
    }

    /**
     * Build the scope entry for a function declaration.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens    Token stream
     * @param  int                                           $index     Index of the T_FUNCTION token
     * @param  int                                           $count     Total token count
     * @param  string                                        $enclosing Name of the enclosing class-like scope
     * @return array{0: int, 1: int, 2: string}|null         Scope entry, null when the declaration has no mutable body
     */
    private static function functionScope(array $tokens, int $index, int $count, string $enclosing): ?array
    {
        $name = self::declaredName($tokens, $index, $count);
        if ($name === null) {
            // Closures and arrow functions stay attributed to their enclosing
            // scope; a synthetic name would not be stable.
            return null;
        }

        $body = self::bodySpan($tokens, $index, $count);
        if ($body === null) {
            // Abstract and interface methods have no body to mutate.
            return null;
        }

        return [$body[0], $body[1], ($enclosing === '' ? '' : $enclosing . '::') . $name];
    }

    /**
     * Return the identifier that names a declaration, when it has one.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens Token stream
     * @param  int                                           $index  Index of the declaring keyword
     * @param  int                                           $count  Total token count
     * @return string|null                                   Declared name, or null for anonymous declarations
     */
    private static function declaredName(array $tokens, int $index, int $count): ?string
    {
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if (\is_string($token)) {
                // '(' opens a closure signature, '{' an anonymous class body.
                return null;
            }

            if ($token[0] === \T_WHITESPACE || $token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                continue;
            }

            if ($token[0] === \T_STRING) {
                return $token[1];
            }

            return null;
        }

        return null;
    }

    /**
     * Find the line span of the body that follows a function declaration.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens Token stream
     * @param  int                                           $index  Index of the T_FUNCTION token
     * @param  int                                           $count  Total token count
     * @return array{0: int, 1: int}|null                    Start and end line, null when the declaration has no body
     */
    private static function bodySpan(array $tokens, int $index, int $count): ?array
    {
        $depth = 0;
        $start = null;

        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if (!\is_string($token)) {
                if ($start !== null && self::opensInterpolation($token[0])) {
                    // Closed by a plain '}', so it has to be counted here too.
                    $depth++;
                }

                continue;
            }

            if ($token === ';' && $start === null) {
                return null;
            }

            if ($token === '{') {
                if ($start === null) {
                    $start = self::lineOf($tokens, $cursor);
                }

                $depth++;

                continue;
            }

            if ($token === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    return [$start, self::lineOf($tokens, $cursor)];
                }
            }
        }

        return null;
    }

    /**
     * Resolve the line a brace sits on by walking back to the nearest token that carries one.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens Token stream
     * @param  int                                           $index  Index of the token to locate
     * @return int                                           1 based line number, zero when no preceding token carries one
     */
    private static function lineOf(array $tokens, int $index): int
    {
        for ($cursor = $index; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (\is_array($token)) {
                // Array tokens report the line they start on, so a multi line
                // token has to be walked to its end.
                return $token[2] + substr_count($token[1], "\n");
            }
        }

        return 0;
    }
}

/**
 * The paths and mode a single invocation runs with.
 */
final readonly class Invocation
{
    /**
     * Build a parsed invocation.
     *
     * @param bool     $update       Whether the baseline should be rewritten instead of checked
     * @param string   $reportPath   Absolute path to the Infection JSON report
     * @param string   $baselinePath Absolute path to the committed baseline
     * @param int|null $exitCode     Exit code when parsing already settled the run, null to continue
     */
    public function __construct(
        public bool $update,
        public string $reportPath,
        public string $baselinePath,
        public ?int $exitCode,
    ) {
    }
}

/**
 * Entry point comparing the latest Infection run against the baseline.
 */
final class MutationBaseline
{
    /**
     * Run the comparison or rewrite the baseline.
     *
     * @param  list<string> $arguments   Command line arguments without the script name
     * @param  mixed        $errorStream Stream diagnostics are written to; STDERR when null
     * @return int          Process exit code
     */
    public static function run(array $arguments, mixed $errorStream = null): int
    {
        $rootDir    = \dirname(__DIR__);
        $invocation = self::parseArguments($arguments, $rootDir, $errorStream);

        if ($invocation->exitCode !== null) {
            return $invocation->exitCode;
        }

        $reportPath   = $invocation->reportPath;
        $baselinePath = $invocation->baselinePath;
        $update       = $invocation->update;

        try {
            $report  = self::loadReport($reportPath);
            $skipped = Json::asInt(Json::asArray($report['stats'] ?? null)['skippedCount'] ?? null);
            if ($skipped > 0) {
                return self::reportSkipped($skipped);
            }

            $allowed = array_merge(
                self::readSection($report, 'escaped', $rootDir),
                self::readSection($report, 'timeouted', $rootDir),
                self::readSection($report, 'errored', $rootDir),
            );

            return $update
                ? self::write($baselinePath, $allowed)
                : self::check($baselinePath, $allowed);
        } catch (\RuntimeException $exception) {
            self::writeError($errorStream, $exception->getMessage());

            return 2;
        }
    }

    /**
     * Turn command line arguments into the paths and mode to run with.
     *
     * @param  list<string> $arguments   Command line arguments without the script name
     * @param  string       $rootDir     Repository root, used to build the default paths
     * @param  mixed        $errorStream Stream diagnostics are written to; STDERR when null
     * @return Invocation   Parsed invocation, carrying an exit code when the run should stop
     */
    private static function parseArguments(array $arguments, string $rootDir, mixed $errorStream): Invocation
    {
        $reportPath   = $rootDir . '/build/infection/infection.json';
        $baselinePath = $rootDir . '/.claude/mutation-baseline.json';
        $update       = false;

        foreach ($arguments as $argument) {
            if ($argument === '--update') {
                $update = true;
            } elseif ($argument === '-h' || $argument === '--help') {
                echo self::usage(), \PHP_EOL;

                return new Invocation($update, $reportPath, $baselinePath, 0);
            } elseif (str_starts_with($argument, '--report=')) {
                $reportPath = substr($argument, \strlen('--report='));
            } elseif (str_starts_with($argument, '--baseline=')) {
                $baselinePath = substr($argument, \strlen('--baseline='));
            } else {
                self::writeError($errorStream, 'unknown argument: ' . $argument . \PHP_EOL . self::usage());

                return new Invocation($update, $reportPath, $baselinePath, 2);
            }
        }

        return new Invocation($update, $reportPath, $baselinePath, null);
    }

    /**
     * Read the Infection report and reject one that carries no mutants.
     *
     * A truncated or schema-drifted report yields empty sections, which would
     * otherwise be indistinguishable from a clean run.
     *
     * @param  string                  $reportPath Absolute path to the Infection JSON report
     * @return array<array-key, mixed> Decoded report
     * @throws \RuntimeException       When the report is unusable or holds no mutants
     */
    private static function loadReport(string $reportPath): array
    {
        $report = Json::readFile(
            $reportPath,
            'Run Infection first: vendor/bin/infection --threads=4 --no-progress',
        );

        $total = Json::asInt(Json::asArray($report['stats'] ?? null)['totalMutantsCount'] ?? null);
        if ($total <= 0) {
            throw new \RuntimeException(
                'no mutants in ' . $reportPath . ' (stats.totalMutantsCount is ' . $total . ').' . \PHP_EOL
                . 'The report is truncated or written by an incompatible Infection version; rerun Infection.',
            );
        }

        return $report;
    }

    /**
     * Write a diagnostic to the error stream.
     *
     * @param  mixed  $errorStream Stream to write to; STDERR when it is not a stream
     * @param  string $message     Diagnostic without a trailing newline
     * @return void
     */
    private static function writeError(mixed $errorStream, string $message): void
    {
        fwrite(\is_resource($errorStream) ? $errorStream : \STDERR, $message . \PHP_EOL);
    }

    /**
     * Return the command line synopsis.
     *
     * @return string Usage text
     */
    private static function usage(): string
    {
        return 'Usage: .claude/mutation-baseline.php [--update] [--report=PATH] [--baseline=PATH]';
    }

    /**
     * Read one result section of the Infection report.
     *
     * @param  array<array-key, mixed> $report  Decoded Infection JSON report
     * @param  string                  $section Section name such as escaped or timeouted
     * @param  string                  $rootDir Repository root, stripped from reported paths
     * @return list<Mutant>            Mutants in report order, duplicates kept
     */
    private static function readSection(array $report, string $section, string $rootDir): array
    {
        $mutants = [];
        foreach (Json::asArray($report[$section] ?? null) as $entry) {
            $mutant = Mutant::fromReportEntry(Json::asArray($entry), $rootDir);
            if ($mutant instanceof Mutant) {
                // Duplicates are kept: one method can hold two identical
                // mutable statements, and collapsing them would let one
                // regress while the other covers for it in the baseline.
                $mutants[] = $mutant;
            }
        }

        return $mutants;
    }

    /**
     * Read the committed baseline as occurrence counts per identity.
     *
     * @param  string             $path Absolute path to the baseline file
     * @return array<string, int> Allowed occurrence count per key
     * @throws \RuntimeException  When the baseline is missing or unusable
     */
    private static function readBaselineCounts(string $path): array
    {
        $baseline = Json::readFile($path, 'Create it once with: .claude/mutation-baseline.php --update');

        $counts = [];
        foreach (Json::asArray($baseline['allowed'] ?? null) as $entry) {
            $key          = Mutant::fromBaselineEntry(Json::asArray($entry))->key();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Count mutants per identity.
     *
     * @param  list<Mutant>       $mutants Mutants to tally
     * @return array<string, int> Occurrence count per key
     */
    private static function countByKey(array $mutants): array
    {
        $counts = [];
        foreach ($mutants as $mutant) {
            $counts[$mutant->key()] = ($counts[$mutant->key()] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Rewrite the baseline file from the latest run.
     *
     * @param  string       $path    Absolute path to the baseline file
     * @param  list<Mutant> $allowed Mutants allowed to survive
     * @return int          Process exit code
     */
    private static function write(string $path, array $allowed): int
    {
        $mutants = $allowed;
        usort($mutants, static fn (Mutant $a, Mutant $b): int => $a->key() <=> $b->key());

        $encoded = json_encode(
            [
                'note'    => 'Mutants allowed to survive. Regenerate with .claude/mutation-baseline.php --update '
                    . 'and justify every added entry in the pull request; the gate is only as strong as that review.',
                'allowed' => array_map(static fn (Mutant $mutant): array => $mutant->toArray(), $mutants),
            ],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        if ($encoded === false) {
            fwrite(\STDERR, 'cannot encode baseline' . \PHP_EOL);

            return 2;
        }

        file_put_contents($path, $encoded . \PHP_EOL);
        echo 'baseline updated: ', \count($mutants), ' allowed mutants', \PHP_EOL;

        return 0;
    }

    /**
     * Compare the latest run against the baseline.
     *
     * @param  string       $path    Absolute path to the baseline file
     * @param  list<Mutant> $allowed Mutants that survived in any form in the latest run
     * @return int          Process exit code
     */
    private static function check(string $path, array $allowed): int
    {
        $baselineCounts = self::readBaselineCounts($path);
        $allowedCounts  = self::countByKey($allowed);
        $resolved       = \count(array_diff_key($baselineCounts, $allowedCounts));
        if ($resolved > 0) {
            echo $resolved, ' baseline entries no longer survive.', \PHP_EOL,
            'Shrink the baseline with --update once the run is stable.', \PHP_EOL, \PHP_EOL;
        }

        // Compared by occurrence count, not by presence: a second identical
        // mutant in the same method is a regression even when the first one
        // is baselined.
        $seen        = [];
        $regressions = [];
        foreach ($allowed as $mutant) {
            $key        = $mutant->key();
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > ($baselineCounts[$key] ?? 0)) {
                $regressions[] = $mutant;
            }
        }

        if ($regressions === []) {
            echo 'No new surviving mutants (', \count($allowed), ' survived, all in the baseline).', \PHP_EOL;

            return 0;
        }

        echo \count($regressions), ' mutant(s) survived that are not in the baseline:', \PHP_EOL, \PHP_EOL;
        foreach ($regressions as $mutant) {
            echo $mutant->describe(), \PHP_EOL, \PHP_EOL;
        }

        echo 'Add a test that kills them. If a mutant is equivalent, ignore it in infection.json5 ',
        'with a reason instead of widening the baseline.', \PHP_EOL, \PHP_EOL,
        'A mutant that timed out or errored reached no assertion at all, so decide which it is ',
        'before adding it here: put a bounded stand-in of the mutation in place by hand and see ',
        'whether a test names the behaviour.', \PHP_EOL,
        'Some mutations only ever hang, with output identical to the original until they do. ',
        'No assertion can separate those from the original and the timeout is the detection, ',
        'so the baseline is where they belong, with the reason written down.', \PHP_EOL;

        return 1;
    }

    /**
     * Report a run that left mutants unexecuted.
     *
     * @param  int $skipped Number of mutants Infection did not run
     * @return int Process exit code
     */
    private static function reportSkipped(int $skipped): int
    {
        echo $skipped, ' mutant(s) were skipped, so the run is incomplete.', \PHP_EOL, \PHP_EOL,
        'Infection skips a mutant whose tests are already expected to exceed the timeout, and ',
        'drops it from the denominator of the score.', \PHP_EOL,
        'The effect is an MSI that rises as fewer mutants run. Raise "timeout" in infection.json5 ',
        'or make the covering tests faster, then rerun.', \PHP_EOL;

        return 1;
    }
}

$invocation = [];
foreach (Json::asArray($_SERVER['argv'] ?? null) as $argument) {
    if (\is_string($argument)) {
        $invocation[] = $argument;
    }
}

// Only run when executed directly. Tests load this file to exercise the
// classes, and a bare exit() would take the test runner down with it.
if ($invocation !== [] && realpath($invocation[0]) === __FILE__) {
    exit(MutationBaseline::run(\array_slice($invocation, 1)));
}
