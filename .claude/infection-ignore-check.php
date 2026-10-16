<?php

declare(strict_types=1);

/*
 * Checks that the ignore entries of infection.json5 only hide mutants that no
 * test kills.
 *
 * An ignore entry names a class, a method or one line. Infection drops every
 * mutant it matches before running it, so an entry that also matches a mutant
 * the tests would kill hides that test's signal, and nothing reports it. The
 * check runs Infection twice over the files the entries name, with and without
 * the entries, and compares the two: a mutant that appears only without the
 * entries is one they hid. It fails when a hidden mutant is killed, and when an
 * entry hides nothing.
 *
 * Which mutants the entries hide is decided by Infection itself (the difference
 * between the two runs); this script only assigns each hidden mutant to the
 * entry that matched it, to name the entry in the message. A hidden mutant no
 * entry can be assigned to is reported as an error of this script.
 *
 * Usage:
 *   .claude/infection-ignore-check.php                          # run both passes
 *   .claude/infection-ignore-check.php --with-report=<path>     # reuse a report of a run with the entries
 *
 * The report given with --with-report must come from a run of the current
 * sources with the current infection.json5, such as the one the quality gate
 * has just made. Exit codes: 0 clean, 1 an entry hides a killed mutant or
 * hides nothing, 2 the check itself could not run.
 */

namespace Sloop\Tooling;

use ColinODell\Json5\Json5Decoder;

/**
 * One mutant of an Infection report, identified by where and how it mutates.
 */
final readonly class ReportedMutant
{
    /**
     * Build a mutant record.
     *
     * @param string $file    Source path relative to the repository root
     * @param int    $line    1 based line of the mutated statement
     * @param string $mutator Mutator name
     * @param string $diff    Diff Infection reported
     * @param bool   $killed  Whether a test killed it
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $mutator,
        public string $diff,
        public bool $killed,
    ) {
    }

    /**
     * Identity of the mutant, equal for the same mutation in two runs.
     *
     * @return string
     */
    public function key(): string
    {
        return $this->file . "\0" . $this->line . "\0" . $this->mutator . "\0" . $this->diff;
    }

    /**
     * Read the mutants of an Infection JSON report that lie in the given files.
     *
     * @param  array<array-key, mixed> $report  Decoded report
     * @param  string                  $rootDir Absolute repository root
     * @param  list<string>            $files   Relative paths to keep
     * @return list<self>
     */
    public static function fromReport(array $report, string $rootDir, array $files): array
    {
        $keep    = array_fill_keys($files, true);
        $mutants = [];
        foreach (['killed' => true, 'killedByStaticAnalysis' => true, 'escaped' => false, 'timeouted' => false, 'errored' => false, 'uncovered' => false, 'syntaxErrors' => false] as $bucket => $killed) {
            foreach (Json::asArray($report[$bucket] ?? null) as $entry) {
                $mutant = self::fromEntry(Json::asArray($entry), $rootDir, $killed);
                if (isset($keep[$mutant->file])) {
                    $mutants[] = $mutant;
                }
            }
        }

        return $mutants;
    }

    /**
     * Read one mutant of a report.
     *
     * @param  array<array-key, mixed> $entry   One entry of a report bucket
     * @param  string                  $rootDir Absolute repository root
     * @param  bool                    $killed  Whether the bucket holds killed mutants
     * @return self
     */
    private static function fromEntry(array $entry, string $rootDir, bool $killed): self
    {
        $mutator = Json::asArray($entry['mutator'] ?? null);
        $path    = Json::asString($mutator['originalFilePath'] ?? null);

        return new self(
            str_starts_with($path, $rootDir . '/') ? substr($path, \strlen($rootDir) + 1) : $path,
            Json::asInt($mutator['originalStartLine'] ?? null),
            Json::asString($mutator['mutatorName'] ?? null),
            Json::asString($entry['diff'] ?? null),
            $killed,
        );
    }
}

/**
 * The ignore entries of an Infection config, per mutator.
 */
final readonly class IgnoreEntries
{
    /**
     * Build the entry set.
     *
     * @param array<string, list<string>> $byMutator Entries listed under each mutator
     * @param list<string>                $global    Entries of "global-ignore", which apply to every mutator
     */
    public function __construct(
        public array $byMutator,
        public array $global,
    ) {
    }

    /**
     * Read the entries of a decoded mutators section.
     *
     * @param  array<array-key, mixed> $mutators Decoded "mutators" section
     * @return self
     */
    public static function fromMutators(array $mutators): self
    {
        $byMutator = [];
        $global    = [];
        foreach ($mutators as $name => $settings) {
            if (!\is_array($settings)) {
                continue;
            }
            $list    = $name === 'global-ignore' ? $settings : Json::asArray($settings['ignore'] ?? null);
            $entries = array_values(array_filter($list, \is_string(...)));
            if ($entries === []) {
                continue;
            }
            if ($name === 'global-ignore') {
                $global = $entries;
            } else {
                $byMutator[(string) $name] = $entries;
            }
        }

        return new self($byMutator, $global);
    }

    /**
     * Every entry with the mutator it is listed under, "global-ignore" for global ones.
     *
     * @return list<array{string, string}>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->byMutator as $mutator => $entries) {
            foreach ($entries as $entry) {
                $all[] = [$mutator, $entry];
            }
        }
        foreach ($this->global as $entry) {
            $all[] = ['global-ignore', $entry];
        }

        return $all;
    }

    /**
     * The entries that apply to a mutator, each with the list it comes from.
     *
     * @param  string                      $mutator Mutator name
     * @return list<array{string, string}>
     */
    public function forMutator(string $mutator): array
    {
        $entries = [];
        foreach ($this->byMutator[$mutator] ?? [] as $entry) {
            $entries[] = [$mutator, $entry];
        }
        foreach ($this->global as $entry) {
            $entries[] = ['global-ignore', $entry];
        }

        return $entries;
    }

    /**
     * The class part of every entry, which may hold fnmatch wildcards.
     *
     * @return list<string>
     */
    public function classPatterns(): array
    {
        $patterns = [];
        foreach ($this->all() as [, $entry]) {
            $patterns[explode('::', $entry, 2)[0]] = true;
        }

        return array_keys($patterns);
    }
}

/**
 * Finds the method a line belongs to the way Infection names it in ignore entries.
 *
 * Infection gives a mutant the method of the node it mutates, which includes the
 * signature: a parameter default or a visibility keyword belongs to its method.
 * The span therefore starts at the `function` keyword, not at the body. (The
 * ScopeIndex of mutation-baseline.php starts at the body and is left as it is,
 * since its names are part of the baseline's identities.)
 */
final class MethodSpans
{
    /**
     * Spans per file: start line, end line and method name.
     *
     * @var array<string, list<array{int, int, string}>>
     */
    private static array $cache = [];

    /**
     * The name of the innermost named function that spans the line, or null.
     *
     * @param  string      $file Absolute path to a PHP file
     * @param  int         $line 1 based line
     * @return string|null
     */
    public static function methodAt(string $file, int $line): ?string
    {
        self::$cache[$file] ??= self::collect($file);
        $best                 = null;
        $size                 = \PHP_INT_MAX;
        foreach (self::$cache[$file] as [$start, $end, $name]) {
            if ($line >= $start && $line <= $end && $end - $start < $size) {
                $best = $name;
                $size = $end - $start;
            }
        }

        return $best;
    }

    /**
     * Collect the spans of the named functions of a file.
     *
     * @param  string                        $file Absolute path to a PHP file
     * @return list<array{int, int, string}>
     */
    private static function collect(string $file): array
    {
        $source = is_file($file) ? file_get_contents($file) : false;
        if ($source === false) {
            return [];
        }
        $tokens = array_values(\PhpToken::tokenize($source));
        $spans  = [];
        foreach ($tokens as $index => $token) {
            if (!$token->is(\T_FUNCTION)) {
                continue;
            }
            $nameAt = self::nextSignificant($tokens, $index + 1);
            if ($nameAt === null || !$tokens[$nameAt]->is(\T_STRING)) {
                // A closure or an arrow function: Infection names its mutants after the enclosing method.
                continue;
            }
            $open = self::bodyOpen($tokens, $nameAt);
            $end  = $open === null ? null : self::closingLine($tokens, $open);
            if ($end !== null) {
                $spans[] = [$token->line, $end, $tokens[$nameAt]->text];
            }
        }

        return $spans;
    }

    /**
     * Index of the first token at or after $from that is not whitespace or a comment.
     *
     * @param  list<\PhpToken> $tokens Tokens of the file
     * @param  int             $from   Index to start at
     * @return int|null
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = \count($tokens);
        for ($index = $from; $index < $count; $index++) {
            if (!$tokens[$index]->isIgnorable()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Index of the brace that opens the body after a function name, past the parameter list.
     *
     * @param  list<\PhpToken> $tokens Tokens of the file
     * @param  int             $from   Index of the function name
     * @return int|null        Null for a function without a body (abstract or in an interface)
     */
    private static function bodyOpen(array $tokens, int $from): ?int
    {
        $count  = \count($tokens);
        $parens = 0;
        for ($index = $from; $index < $count; $index++) {
            $text    = $tokens[$index]->text;
            $parens += match ($text) {
                '('     => 1,
                ')'     => -1,
                default => 0,
            };
            if ($parens === 0 && ($text === ';' || $text === '{')) {
                return $text === '{' ? $index : null;
            }
        }

        return null;
    }

    /**
     * Line of the brace that closes the one at $open.
     *
     * @param  list<\PhpToken> $tokens Tokens of the file
     * @param  int             $open   Index of an opening brace
     * @return int|null
     */
    private static function closingLine(array $tokens, int $open): ?int
    {
        $count = \count($tokens);
        $depth = 0;
        for ($index = $open; $index < $count; $index++) {
            $token = $tokens[$index];
            // Interpolation openers ("{$" and "${") are closed by a plain "}".
            if ($token->text === '{' || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}' && --$depth === 0) {
                return $token->line;
            }
        }

        return null;
    }
}

/**
 * Runs the check.
 */
final class IgnoreCheck
{
    /**
     * Directory the runs of this check write their reports to.
     *
     * @var string
     */
    private const string BUILD_DIR = 'build/infection-ignore-check';

    /**
     * Entry point.
     *
     * @param  list<string> $args Command line arguments after the script name
     * @return int          Exit code
     */
    public static function run(array $args): int
    {
        $rootDir = \dirname(__DIR__);
        try {
            $withReport = null;
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--with-report=')) {
                    $withReport = substr($arg, \strlen('--with-report='));
                } else {
                    throw new \RuntimeException('unknown argument: ' . $arg);
                }
            }

            $config  = self::readConfig($rootDir . '/infection.json5');
            $entries = IgnoreEntries::fromMutators(Json::asArray($config['mutators'] ?? null));
            if ($entries->all() === []) {
                echo 'infection.json5 has no ignore entries, so there is nothing to check.', \PHP_EOL;

                return 0;
            }
            $files = self::filesFor($entries->classPatterns(), $rootDir);

            $with    = $withReport === null
                ? self::runInfection($rootDir, self::configWithout($config, false), 'with', $files)
                : Json::readFile($withReport, 'Run Infection first, or omit --with-report to let this script run it.');
            $without = self::runInfection($rootDir, self::configWithout($config, true), 'without', $files);

            $exitCode = self::report(
                self::evaluate(
                    $entries,
                    ReportedMutant::fromReport($with, $rootDir, $files),
                    ReportedMutant::fromReport($without, $rootDir, $files),
                    $rootDir,
                ),
            );
            // The quality gate reads this line as the number of items checked.
            echo 'Checked ', \count($entries->all()), ' ignore entries.', \PHP_EOL;

            return $exitCode;
        } catch (\RuntimeException $exception) {
            fwrite(\STDERR, $exception->getMessage() . \PHP_EOL);

            return 2;
        }
    }

    /**
     * Compare the two runs and sort the hidden mutants under the entries that hid them.
     *
     * @param  IgnoreEntries                                                                                             $entries Entries of the config
     * @param  list<ReportedMutant>                                                                                      $with    Mutants of the run with the entries
     * @param  list<ReportedMutant>                                                                                      $without Mutants of the run without the entries
     * @param  string                                                                                                    $rootDir Absolute repository root
     * @return array{killed: array<string, list<ReportedMutant>>, empty: list<string>, unassigned: list<ReportedMutant>}
     */
    public static function evaluate(IgnoreEntries $entries, array $with, array $without, string $rootDir): array
    {
        $hits       = [];
        $killed     = [];
        $unassigned = [];
        foreach (self::hidden($with, $without) as $mutant) {
            $label = self::entryFor($entries, $mutant, $rootDir);
            if ($label === null) {
                $unassigned[] = $mutant;

                continue;
            }
            $hits[$label] = true;
            if ($mutant->killed) {
                $killed[$label][] = $mutant;
            }
        }

        $empty = [];
        foreach ($entries->all() as [$list, $entry]) {
            if (!isset($hits[$list . ' ' . $entry])) {
                $empty[] = $list . ' ' . $entry;
            }
        }

        return ['killed' => $killed, 'empty' => $empty, 'unassigned' => $unassigned];
    }

    /**
     * The mutants of the run without the entries that the run with them does not have.
     *
     * Counted as a multiset: two identical mutations on one line are two mutants.
     *
     * @param  list<ReportedMutant> $with    Mutants of the run with the entries
     * @param  list<ReportedMutant> $without Mutants of the run without the entries
     * @return list<ReportedMutant>
     */
    private static function hidden(array $with, array $without): array
    {
        $present = [];
        foreach ($with as $mutant) {
            $present[$mutant->key()] = ($present[$mutant->key()] ?? 0) + 1;
        }

        $hidden = [];
        foreach ($without as $mutant) {
            if (($present[$mutant->key()] ?? 0) > 0) {
                $present[$mutant->key()]--;
            } else {
                $hidden[] = $mutant;
            }
        }

        return $hidden;
    }

    /**
     * The entry that matches a mutant, as "<list> <entry>", with the same patterns Infection checks.
     *
     * @param  IgnoreEntries  $entries Entries of the config
     * @param  ReportedMutant $mutant  A hidden mutant
     * @param  string         $rootDir Absolute repository root
     * @return string|null
     */
    private static function entryFor(IgnoreEntries $entries, ReportedMutant $mutant, string $rootDir): ?string
    {
        $class      = self::classOf($mutant->file);
        $method     = MethodSpans::methodAt($rootDir . '/' . $mutant->file, $mutant->line);
        $candidates = [$class];
        if ($method !== null) {
            $candidates[] = $class . '::' . $method;
            $candidates[] = $class . '::' . $method . '::' . $mutant->line;
        }
        foreach ($entries->forMutator($mutant->mutator) as [$list, $entry]) {
            foreach ($candidates as $candidate) {
                if (fnmatch($entry, $candidate, \FNM_NOESCAPE)) {
                    return $list . ' ' . $entry;
                }
            }
        }

        return null;
    }

    /**
     * The class a source file under src/ declares, by the PSR-4 mapping of the project.
     *
     * @param  string $file Relative path such as src/Sloop/Support/Str.php
     * @return string
     */
    private static function classOf(string $file): string
    {
        return str_replace('/', '\\', substr($file, \strlen('src/'), -\strlen('.php')));
    }

    /**
     * The source files of the classes the entries name, wildcards included.
     *
     * @param  list<string>      $patterns Class part of each entry
     * @param  string            $rootDir  Absolute repository root
     * @return list<string>      Relative paths
     * @throws \RuntimeException When a pattern matches no file
     */
    private static function filesFor(array $patterns, string $rootDir): array
    {
        $all      = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rootDir . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $info) {
            if ($info instanceof \SplFileInfo && $info->getExtension() === 'php') {
                $all[] = substr($info->getPathname(), \strlen($rootDir) + 1);
            }
        }
        sort($all);

        $files = [];
        foreach ($patterns as $pattern) {
            $matched = false;
            foreach ($all as $file) {
                if (fnmatch($pattern, self::classOf($file), \FNM_NOESCAPE)) {
                    $files[$file] = true;
                    $matched      = true;
                }
            }
            if (!$matched) {
                throw new \RuntimeException('no file under src/ declares ' . $pattern . ', which an ignore entry names.');
            }
        }

        return array_keys($files);
    }

    /**
     * Read infection.json5.
     *
     * @param  string                  $path Absolute path
     * @return array<array-key, mixed>
     * @throws \RuntimeException       When the file cannot be read
     */
    private static function readConfig(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new \RuntimeException('cannot read ' . $path);
        }

        return Json::asArray(Json5Decoder::decode($contents, true));
    }

    /**
     * The config for one run: logs under this check's directory, no MSI floor, and optionally no ignore entries.
     *
     * @param  array<array-key, mixed> $config      Decoded infection.json5
     * @param  bool                    $dropEntries Remove every ignore entry
     * @return array<array-key, mixed>
     */
    public static function configWithout(array $config, bool $dropEntries): array
    {
        // A run over part of the sources is measured against nothing, so the floor would only fail it.
        unset($config['minMsi'], $config['minCoveredMsi']);
        $config['tmpDir'] = self::BUILD_DIR . '/tmp';
        $config['logs']   = ['json' => self::BUILD_DIR . '/' . ($dropEntries ? 'without' : 'with') . '.json'];
        if (!$dropEntries) {
            return $config;
        }

        $mutators = Json::asArray($config['mutators'] ?? null);
        unset($mutators['global-ignore']);
        foreach ($mutators as $name => $settings) {
            if (!\is_array($settings) || !\array_key_exists('ignore', $settings)) {
                continue;
            }
            unset($settings['ignore']);
            $mutators[$name] = $settings === [] ? true : $settings;
        }
        $config['mutators'] = $mutators;

        return $config;
    }

    /**
     * Run Infection over the files with a config and return its JSON report.
     *
     * @param  string                  $rootDir Absolute repository root
     * @param  array<array-key, mixed> $config  Config to run with
     * @param  string                  $name    "with" or "without", for the file names
     * @param  list<string>            $files   Relative paths to mutate
     * @return array<array-key, mixed>
     * @throws \RuntimeException       When Infection does not write a report
     */
    private static function runInfection(string $rootDir, array $config, string $name, array $files): array
    {
        // Infection resolves the paths of a config against the directory the config is in,
        // so the config has to sit at the repository root.
        $configPath = $rootDir . '/infection-ignore-check.' . $name . '.json';
        $reportPath = $rootDir . '/' . self::BUILD_DIR . '/' . $name . '.json';
        if (is_file($reportPath)) {
            unlink($reportPath);
        }
        if (file_put_contents($configPath, json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('cannot write ' . $configPath);
        }
        try {
            $command = 'vendor/bin/infection --threads=4 --no-progress --configuration=' . escapeshellarg($configPath)
                . ' ' . implode(' ', array_map(escapeshellarg(...), $files));
            echo 'Infection ', $name, ' the ignore entries, over ', \count($files), ' files', \PHP_EOL;
            $exitCode = 0;
            passthru('cd ' . escapeshellarg($rootDir) . ' && ' . $command, $exitCode);
        } finally {
            unlink($configPath);
        }
        // Without an MSI floor Infection exits 0 on a finished run, whatever the mutants did; anything else
        // (a failing initial test run, a bad config) leaves a report that does not describe the sources.
        if ($exitCode !== 0) {
            throw new \RuntimeException('Infection ' . $name . ' the ignore entries exited with ' . $exitCode . '; see its output above.');
        }

        return Json::readFile($reportPath, 'Infection did not write a report; see its output above.');
    }

    /**
     * Print the result.
     *
     * @param  array{killed: array<string, list<ReportedMutant>>, empty: list<string>, unassigned: list<ReportedMutant>} $result Result of evaluate()
     * @return int                                                                                                       Exit code
     */
    private static function report(array $result): int
    {
        if ($result['unassigned'] !== []) {
            foreach ($result['unassigned'] as $mutant) {
                fwrite(\STDERR, 'A mutant the ignore entries hid matches no entry here: ' . $mutant->file . ':' . $mutant->line . ' ' . $mutant->mutator . \PHP_EOL);
            }
            fwrite(\STDERR, 'This script matches entries differently from Infection; fix the script before trusting its result.' . \PHP_EOL);

            return 2;
        }

        foreach ($result['killed'] as $label => $mutants) {
            echo 'Hides ', \count($mutants), ' killed mutant(s): ', $label, \PHP_EOL;
            foreach ($mutants as $mutant) {
                echo '    ', $mutant->file, ':', $mutant->line, \PHP_EOL;
            }
        }
        foreach ($result['empty'] as $label) {
            echo 'Hides no mutant: ', $label, \PHP_EOL;
        }

        if ($result['killed'] === [] && $result['empty'] === []) {
            echo 'Every ignore entry hides at least one mutant, and none that a test kills.', \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL, 'An entry that hides a killed mutant hides a test\'s signal: narrow it to the line, or drop it if the',
        \PHP_EOL, 'mutants it was for are now killed. An entry that hides nothing is stale: drop it.', \PHP_EOL;

        return 1;
    }
}

// The Json helper comes from mutation-baseline.php through the classmap autoloader.
require_once \dirname(__DIR__) . '/vendor/autoload.php';

$arguments = [];
foreach (Json::asArray($_SERVER['argv'] ?? null) as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

// Only run when executed directly, so tests can load the classes.
if ($arguments !== [] && realpath($arguments[0]) === __FILE__) {
    // Infection's JSON report holds every mutant with its test output, a few hundred megabytes
    // for the files the entries name, and decoding it needs more than the default memory limit.
    ini_set('memory_limit', '-1');
    exit(IgnoreCheck::run(\array_slice($arguments, 1)));
}
