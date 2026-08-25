<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use ColinODell\Json5\Json5Decoder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class InfectionIgnorePinTest extends TestCase
{
    private const string CONFIG = __DIR__ . '/../../../infection.json5';

    /**
     * Characters fnmatch() treats as wildcards under FNM_NOESCAPE.
     *
     * IgnoreConfig::isIgnored() matches every entry with fnmatch(), so an
     * entry carrying one of these does not name a single line and cannot be
     * checked against one.
     */
    private const string WILDCARDS = '*?[';

    /**
     * Read the mutators section of the Infection config.
     *
     * The file is JSON5, so it is parsed with the same decoder Infection
     * itself uses rather than a hand written scanner over its comments.
     *
     * @return array<array-key, mixed> Settings keyed by mutator, profile or global setting
     */
    private function mutatorSettings(): array
    {
        $contents = file_get_contents(self::CONFIG);
        if ($contents === false) {
            self::fail('Could not read ' . self::CONFIG);
        }

        $config = Json5Decoder::decode($contents, true);
        if (!\is_array($config)) {
            self::fail('infection.json5 did not decode to an object.');
        }

        $mutators = $config['mutators'] ?? null;
        if (!\is_array($mutators)) {
            self::fail('infection.json5 has no mutators section, so no ignore entry could be read.');
        }

        return $mutators;
    }

    /**
     * Read every ignore entry from the Infection config.
     *
     * Entries live either under a mutator's own "ignore" list or under the
     * "global-ignore" list, which MutatorResolver merges into the ignore list
     * of every mutator.
     *
     * @return list<string> Entries such as Class::method or Class::method::line
     */
    private function ignoreEntries(): array
    {
        $entries = [];
        foreach ($this->mutatorSettings() as $key => $settings) {
            if (!\is_array($settings)) {
                continue;
            }

            $list = $key === 'global-ignore' ? $settings : $settings['ignore'] ?? null;
            if (!\is_array($list)) {
                continue;
            }

            foreach ($list as $entry) {
                if (\is_string($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * Report whether an entry names one line that can be checked.
     *
     * @param  string $entry One ignore entry
     * @return bool   True when the entry is a wildcard free Class::method::line
     */
    private function isCheckablePin(string $entry): bool
    {
        return substr_count($entry, '::') === 2
            && strpbrk($entry, self::WILDCARDS) === false;
    }

    /**
     * Collect the ignore entries that name one checkable line.
     *
     * @return list<string> Entries in Class::method::line form
     */
    private function linePins(): array
    {
        return array_values(array_filter($this->ignoreEntries(), $this->isCheckablePin(...)));
    }

    /**
     * Describe why a line pinned ignore entry no longer matches its method.
     *
     * @param  string      $entry An entry that isCheckablePin() accepted
     * @return string|null Reason the pin is stale, or null when it still holds
     */
    private function pinFailure(string $entry): ?string
    {
        [$class, $method, $line] = explode('::', $entry);
        if (preg_match('/\A\d+\z/', $line) !== 1) {
            return $entry . ' ends in ' . $line . ', which is not a line number.';
        }

        if (!method_exists($class, $method)) {
            return $entry . ' names a method that does not exist.';
        }

        $reflection = new ReflectionMethod($class, $method);
        $start      = $reflection->getStartLine();
        $end        = $reflection->getEndLine();
        $pinned     = (int) $line;
        if ($pinned < $start || $pinned > $end) {
            return $entry . ' is outside ' . $method . '(), which now spans lines '
                . $start . ' to ' . $end . '.';
        }

        return null;
    }

    public function testEveryLinePinnedIgnoreStaysInsideItsMethod(): void
    {
        // Some ignore entries in infection.json5 carry a line so that the other
        // killable mutants of the same method keep their signal. Adding lines
        // above such a method moves the code out from under the pin without a
        // word, and the ignored mutant comes back as escaped under the name of
        // a method nobody touched. This was read by hand until 2026-08-24, when
        // a 12 line addition to Connection.php moved transaction::495 and the
        // mutation baseline reported it as a regression.
        //
        // Two things stay outside the check. A pin that drifts onto another
        // mutable line of the same method still swallows that mutant's signal,
        // and an entry carrying an fnmatch wildcard names no single line at
        // all. dev-runbook.md 5 asks for both to be read by hand.
        $failures = [];
        foreach ($this->linePins() as $entry) {
            $failure = $this->pinFailure($entry);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        self::assertSame([], $failures, 'Stale line pins in infection.json5: ' . implode(' / ', $failures));
    }

    public function testTheConfigStillDeclaresLinePinnedIgnores(): void
    {
        // Without this the guard above passes on an empty list, which is what
        // it would produce if the ignore lists moved under the parser.
        self::assertNotSame(
            [],
            $this->linePins(),
            'No line pinned ignore entry was read from infection.json5. Delete this test together with the '
            . 'last pin if it was removed on purpose; otherwise the parser stopped seeing them.',
        );
    }

    public function testTheScannerReportsAPinThatLeftItsMethod(): void
    {
        // Guards the assertion above against the other way of becoming vacuous:
        // a pinFailure() that returned null for everything would keep the suite
        // green forever. This class is its own fixture, so the case does not
        // move when src does.
        $line = new ReflectionMethod(self::class, 'pinFailure')->getStartLine();

        self::assertNull($this->pinFailure(self::class . '::pinFailure::' . $line));
        self::assertNotNull($this->pinFailure(self::class . '::pinFailure::1'));
        self::assertNotNull($this->pinFailure(self::class . '::pinFailure::x'));
        self::assertNotNull($this->pinFailure(self::class . '::noSuchMethod::1'));
    }

    public function testWildcardEntriesAreLeftToInfection(): void
    {
        // IgnoreConfig::isIgnored() runs every entry through fnmatch(), so all
        // three of these name a set of lines rather than one. Reporting them as
        // "not a line number" would be a false alarm.
        self::assertTrue($this->isCheckablePin('Acme\\Thing::method::42'));
        self::assertFalse($this->isCheckablePin('Acme\\Thing::method'));
        self::assertFalse($this->isCheckablePin('Acme\\Thing::method::4*'));
        self::assertFalse($this->isCheckablePin('Acme\\Thing::method::4?'));
        self::assertFalse($this->isCheckablePin('Acme\\Thing::method::[0-9]2'));
    }
}
