<?php

declare(strict_types=1);

/*
 * Compares the lines of src that the Unit tests leave unexecuted against a
 * committed list and fails when a line is unexecuted that is not on the list.
 *
 * Mutation testing does not see these lines: Infection only mutates code a test
 * runs unless it is given --with-uncovered, which the gate and CI do not pass.
 * A new class, or a new method no test calls, therefore adds no mutant and
 * moves no score. Line coverage of the Unit suite does see them.
 *
 * A line is identified by its file, the method that encloses it and its text,
 * not by its number, so that editing anything above it does not move it. The
 * same text twice in one method is counted twice.
 *
 * Every entry of the list carries the reason the line is left unexecuted. An
 * entry without a reason is refused, so --update cannot widen the list without
 * someone writing down why.
 *
 * Usage:
 *   php .claude/coverage-baseline.php             # check the latest report (exit 1 on a new unexecuted line)
 *   php .claude/coverage-baseline.php --update    # rewrite the list from the latest report, keeping reasons
 *
 * --report=, --baseline= and --root= override the report, the list and the
 * repository root whose src is checked.
 *
 * Run the Unit suite with --coverage-clover first; this script only reads the
 * report. Exit codes: 0 clean, 1 a new unexecuted line, 2 the check could not run.
 */

namespace Sloop\Tooling;

use DOMDocument;
use DOMElement;

require_once __DIR__ . '/mutation-baseline.php';

/**
 * One line of src that no Unit test executes.
 */
final readonly class UncoveredLine
{
    /**
     * Create an unexecuted line.
     *
     * @param string $file   Path relative to the repository root
     * @param string $scope  Enclosing method such as ClassName::methodName, empty outside any
     * @param string $text   Source text of the line, trimmed
     * @param int    $number 1 based line number in the report, 0 for a list entry
     * @param string $reason Why the line is left unexecuted, empty when not given yet
     */
    public function __construct(
        public string $file,
        public string $scope,
        public string $text,
        public int $number = 0,
        public string $reason = '',
    ) {
    }

    /**
     * Read an entry of the committed list.
     *
     * @param  array<array-key, mixed> $entry         Decoded list entry
     * @param  bool                    $requireReason Whether an entry without a reason is refused
     * @return self                    The entry
     * @throws \RuntimeException       When the entry names no file, or gives no reason while one is required
     */
    public static function fromListEntry(array $entry, bool $requireReason = true): self
    {
        $line = new self(
            Json::asString($entry['file'] ?? null),
            Json::asString($entry['scope'] ?? null),
            Json::asString($entry['line'] ?? null),
            0,
            Json::asString($entry['reason'] ?? null),
        );

        if ($line->file === '') {
            throw new \RuntimeException('an entry of the list names no file.');
        }

        if ($requireReason && trim($line->reason) === '') {
            throw new \RuntimeException(
                'the entry for ' . $line->file . ' "' . $line->text . '" gives no reason.' . \PHP_EOL
                . 'Write why no Unit test runs the line, or add a test that runs it and drop the entry.',
            );
        }

        return $line;
    }

    /**
     * Return the identity used to match a line against the list.
     *
     * @return string File, scope and text joined
     */
    public function key(): string
    {
        return $this->file . "\0" . $this->scope . "\0" . $this->text;
    }

    /**
     * Return a copy that carries a reason.
     *
     * @param  string $reason Why the line is left unexecuted
     * @return self   The same line with the reason
     */
    public function withReason(string $reason): self
    {
        return new self($this->file, $this->scope, $this->text, $this->number, $reason);
    }

    /**
     * Return the line as an entry of the list.
     *
     * @return array{file: string, scope: string, line: string, reason: string} List entry
     */
    public function toArray(): array
    {
        return ['file' => $this->file, 'scope' => $this->scope, 'line' => $this->text, 'reason' => $this->reason];
    }

    /**
     * Describe the line for the operator.
     *
     * @return string Location, scope and text on one line
     */
    public function describe(): string
    {
        $location = $this->number > 0 ? $this->file . ':' . $this->number : $this->file;

        return $location . ($this->scope !== '' ? '  ' . $this->scope : '') . '  | ' . $this->text;
    }
}

/**
 * The check and the update of the list of unexecuted lines.
 */
final class CoverageBaseline
{
    /**
     * Lines of each source file read so far, keyed by absolute path.
     *
     * @var array<string, list<string>>
     */
    private static array $sources = [];

    /**
     * Run the check or the update.
     *
     * @param  list<string> $arguments   Command line arguments without the script name
     * @param  mixed        $errorStream Stream for diagnostics; STDERR when it is not a stream
     * @return int          Process exit code
     */
    public static function run(array $arguments, mixed $errorStream = null): int
    {
        $rootDir      = \dirname(__DIR__);
        $reportPath   = $rootDir . '/build/coverage/clover.xml';
        $baselinePath = $rootDir . '/.claude/coverage-baseline.json';
        $update       = false;

        foreach ($arguments as $argument) {
            if ($argument === '--update') {
                $update = true;
            } elseif (str_starts_with($argument, '--report=')) {
                $reportPath = substr($argument, \strlen('--report='));
            } elseif (str_starts_with($argument, '--baseline=')) {
                $baselinePath = substr($argument, \strlen('--baseline='));
            } elseif (str_starts_with($argument, '--root=')) {
                $rootDir = substr($argument, \strlen('--root='));
            } else {
                self::writeError($errorStream, 'unknown argument: ' . $argument);

                return 2;
            }
        }

        try {
            [$lines, $files] = self::readReport($reportPath, $rootDir);

            // The quality gate reads this line for the number of items inspected.
            echo 'Checked ', $files, ' files of src.', \PHP_EOL;

            return $update
                ? self::write($baselinePath, $lines)
                : self::check($baselinePath, $lines);
        } catch (\RuntimeException $exception) {
            self::writeError($errorStream, $exception->getMessage());

            return 2;
        }
    }

    /**
     * Read the unexecuted lines of src from a Clover report.
     *
     * @param  string                                $reportPath Absolute path to the Clover XML report
     * @param  string                                $rootDir    Repository root, stripped from reported paths
     * @return array{0: list<UncoveredLine>, 1: int} Unexecuted lines in report order, and the number of files of src read
     * @throws \RuntimeException                     When the report is missing, unreadable or names no file of src
     */
    private static function readReport(string $reportPath, string $rootDir): array
    {
        if (!is_file($reportPath)) {
            throw new \RuntimeException(
                'not found: ' . $reportPath . \PHP_EOL
                . 'Run the Unit suite first: vendor/bin/phpunit --exclude-testsuite=Integration --coverage-clover=build/coverage/clover.xml',
            );
        }

        // DOMDocument reports a malformed file as warnings as well as through
        // its return value; the return value is enough here.
        $document = new DOMDocument();
        set_error_handler(static fn (): bool => true);
        try {
            $loaded = $document->load($reportPath);
        } finally {
            restore_error_handler();
        }

        if (!$loaded) {
            throw new \RuntimeException('invalid XML in ' . $reportPath);
        }

        $prefix = $rootDir . '/src/';
        $lines  = [];
        $files  = 0;
        foreach ($document->getElementsByTagName('file') as $file) {
            $path = $file->getAttribute('name');
            if (!str_starts_with($path, $prefix)) {
                continue;
            }

            $files++;
            foreach (self::unexecutedNumbers($file) as $number) {
                $lines[] = new UncoveredLine(
                    substr($path, \strlen($rootDir) + 1),
                    ScopeIndex::resolve($path, $number),
                    self::sourceLine($path, $number),
                    $number,
                );
            }
        }

        // A report of another checkout, or of a run that collected nothing,
        // would otherwise read as a run with every line executed.
        if ($files === 0) {
            throw new \RuntimeException(
                'no file of ' . $prefix . ' in ' . $reportPath . '.' . \PHP_EOL
                . 'The report comes from another checkout or from a run without a coverage driver; rerun it here.',
            );
        }

        return [$lines, $files];
    }

    /**
     * Collect the statement lines of one file of the report that ran zero times.
     *
     * @param  DOMElement $file The file element of the report
     * @return list<int>  Line numbers in report order
     */
    private static function unexecutedNumbers(DOMElement $file): array
    {
        $numbers = [];
        foreach ($file->getElementsByTagName('line') as $line) {
            if ($line->getAttribute('type') === 'stmt' && $line->getAttribute('count') === '0') {
                $numbers[] = (int) $line->getAttribute('num');
            }
        }

        return $numbers;
    }

    /**
     * Read one line of a source file, trimmed.
     *
     * @param  string $path   Absolute path to the source file
     * @param  int    $number 1 based line number
     * @return string The trimmed line, empty when the file or the line cannot be read
     */
    private static function sourceLine(string $path, int $number): string
    {
        if (!\array_key_exists($path, self::$sources)) {
            $contents             = is_file($path) ? file_get_contents($path) : false;
            self::$sources[$path] = $contents === false ? [] : explode("\n", $contents);
        }

        return trim(self::$sources[$path][$number - 1] ?? '');
    }

    /**
     * Read the committed list.
     *
     * @param  string              $path           Absolute path to the list
     * @param  bool                $requireReasons Whether an entry without a reason is refused
     * @return list<UncoveredLine> Entries in the order the file lists them
     * @throws \RuntimeException   When the list is missing or unusable, or an entry gives no reason while one is required
     */
    private static function readBaseline(string $path, bool $requireReasons = true): array
    {
        $list = Json::readFile($path, 'Create it once with: php .claude/coverage-baseline.php --update');

        $lines = [];
        foreach (Json::asArray($list['uncovered'] ?? null) as $entry) {
            $lines[] = UncoveredLine::fromListEntry(Json::asArray($entry), $requireReasons);
        }

        return $lines;
    }

    /**
     * Count lines per identity.
     *
     * @param  list<UncoveredLine> $lines Lines to tally
     * @return array<string, int>  Occurrence count per key
     */
    private static function countByKey(array $lines): array
    {
        $counts = [];
        foreach ($lines as $line) {
            $counts[$line->key()] = ($counts[$line->key()] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Return the lines of one set that the other does not account for, counting repeats.
     *
     * @param  list<UncoveredLine> $lines  Lines to filter, in order
     * @param  list<UncoveredLine> $others Lines that account for one occurrence each
     * @return list<UncoveredLine> The lines left over, in their original order
     */
    private static function without(array $lines, array $others): array
    {
        $available = self::countByKey($others);
        $left      = [];
        foreach ($lines as $line) {
            $key = $line->key();
            if (($available[$key] ?? 0) > 0) {
                $available[$key]--;
            } else {
                $left[] = $line;
            }
        }

        return $left;
    }

    /**
     * Compare the latest report against the list.
     *
     * @param  string              $path  Absolute path to the list
     * @param  list<UncoveredLine> $lines Unexecuted lines of the latest report
     * @return int                 Process exit code
     * @throws \RuntimeException   When the list is missing or unusable, or an entry gives no reason
     */
    private static function check(string $path, array $lines): int
    {
        $listed   = self::readBaseline($path);
        $new      = self::without($lines, $listed);
        $resolved = self::without($listed, $lines);

        if ($resolved !== []) {
            echo \count($resolved), ' listed line(s) are no longer unexecuted (run by a test, changed or removed):', \PHP_EOL;
            foreach ($resolved as $entry) {
                echo '  ', $entry->describe(), \PHP_EOL;
            }

            echo 'Drop them with: php .claude/coverage-baseline.php --update', \PHP_EOL,
            'A line that was only changed comes back as a new entry; carry its reason over.', \PHP_EOL, \PHP_EOL;
        }

        if ($new === []) {
            echo 'No new unexecuted lines (', \count($lines), ' unexecuted, all listed).', \PHP_EOL;

            return 0;
        }

        echo \count($new), ' line(s) of src are executed by no Unit test and not listed:', \PHP_EOL;
        foreach ($new as $line) {
            echo '  ', $line->describe(), \PHP_EOL;
        }

        echo \PHP_EOL, 'Add a Unit test that runs them. A line no test can reach, such as a failure ',
        'branch of a PHP function that cannot be made to fail, goes on the list with the reason: ',
        'run php .claude/coverage-baseline.php --update and fill in the reason of each new entry.', \PHP_EOL;

        return 1;
    }

    /**
     * Rewrite the list from the latest report, keeping the reason of each line already listed.
     *
     * @param  string              $path  Absolute path to the list
     * @param  list<UncoveredLine> $lines Unexecuted lines of the latest report
     * @return int                 Process exit code
     * @throws \RuntimeException   When an existing list is unusable, a source line is not valid UTF-8, or the list cannot be written
     */
    private static function write(string $path, array $lines): int
    {
        // Reasons are not required here: an entry --update just wrote without
        // one must not stop the next --update. The check refuses it instead.
        $reasons = [];
        if (is_file($path)) {
            foreach (self::readBaseline($path, false) as $entry) {
                $reasons[$entry->key()][] = $entry->reason;
            }
        }

        $entries = [];
        $missing = 0;
        foreach ($lines as $line) {
            $key    = $line->key();
            $reason = isset($reasons[$key]) && $reasons[$key] !== [] ? array_shift($reasons[$key]) : '';
            if ($reason === '') {
                $missing++;
            }

            $entries[] = $line->withReason($reason);
        }

        usort(
            $entries,
            static fn (UncoveredLine $a, UncoveredLine $b): int => [$a->file, $a->number] <=> [$b->file, $b->number],
        );

        try {
            $json = json_encode(
                ['uncovered' => array_map(static fn (UncoveredLine $line): array => $line->toArray(), $entries)],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException('cannot encode the list: ' . $exception->getMessage(), 0, $exception);
        }

        if (file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException('cannot write: ' . $path);
        }

        echo 'Wrote ', \count($entries), ' unexecuted line(s) to ', $path, '.', \PHP_EOL;
        if ($missing > 0) {
            echo $missing, ' entry(ies) have no reason yet; write one for each before the check accepts the list.', \PHP_EOL;
        }

        return 0;
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
        fwrite(\is_resource($errorStream) ? $errorStream : \STDERR, 'coverage-baseline: ' . $message . \PHP_EOL);
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
    exit(CoverageBaseline::run(\array_slice($invocation, 1)));
}
