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
     * Read every mutator ignore entry from the Infection config.
     *
     * The file is JSON5, so it is parsed with the same decoder Infection
     * itself uses rather than a hand written scanner over the comments.
     *
     * @return list<string> Entries such as Class::method or Class::method::line
     */
    private function ignoreEntries(): array
    {
        $contents = file_get_contents(self::CONFIG);
        if ($contents === false) {
            self::fail('Could not read ' . self::CONFIG);
        }

        $config = Json5Decoder::decode($contents, true);
        if (!\is_array($config) || !\is_array($config['mutators'] ?? null)) {
            self::fail('infection.json5 has no mutators section, so no ignore entry could be read.');
        }

        $entries = [];
        foreach ($config['mutators'] as $settings) {
            if (!\is_array($settings) || !\is_array($settings['ignore'] ?? null)) {
                continue;
            }

            foreach ($settings['ignore'] as $entry) {
                if (\is_string($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * Describe why a line pinned ignore entry no longer matches its method.
     *
     * Entries without a line, and any entry carrying a wildcard, have nothing
     * to pin and are reported as compliant.
     *
     * @param  string      $entry One ignore entry
     * @return string|null Reason the pin is stale, or null when it still holds
     */
    private function pinFailure(string $entry): ?string
    {
        $parts = explode('::', $entry);
        if (\count($parts) !== 3 || str_contains($entry, '*')) {
            return null;
        }

        [$class, $method, $line] = $parts;
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
        // The check is one sided: it proves the pin still lands inside the
        // method, not that it lands on the line the comment describes. A pin
        // that drifts onto another mutable line of the same method swallows
        // that mutant's signal silently, and dev-runbook.md 5 still asks for
        // that direction to be read by hand.
        $failures = [];
        foreach ($this->ignoreEntries() as $entry) {
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
        // it would produce if the config layout changed under the parser.
        $entries = $this->ignoreEntries();
        $pinned  = array_filter($entries, static fn (string $entry): bool => \count(explode('::', $entry)) === 3);

        self::assertNotSame([], $entries, 'No ignore entry was read from infection.json5.');
        self::assertNotSame(
            [],
            $pinned,
            'No line pinned ignore entry was read from infection.json5. Delete this test together with the '
            . 'last pin if it was removed on purpose; otherwise the parser stopped seeing them.',
        );
    }
}
