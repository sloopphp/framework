<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use Sloop\Tooling\MutationBaseline;
use Sloop\Tooling\ScopeIndex;

final class MutationBaselineTest extends TestCase
{
    private string $workDir;

    private string $output = '';

    private string $errors = '';

    protected function setUp(): void
    {
        $workDir = tempnam(sys_get_temp_dir(), 'mutation-baseline');
        self::assertIsString($workDir);
        unlink($workDir);
        mkdir($workDir);

        $this->workDir = $workDir;
    }

    protected function tearDown(): void
    {
        $files = glob($this->workDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        rmdir($this->workDir);
    }

    public function testKeyIgnoresLineNumbersSoUnrelatedEditsDoNotInvalidateEntries(): void
    {
        // The subject file has to exist and hold a real method, otherwise the
        // scope resolves to '' on both sides and the test degenerates into
        // asserting that key() carries no line field.
        $this->writeSubject(0);
        self::assertSame('Sample::compute', ScopeIndex::resolve($this->workDir . '/Sample.php', 7));

        $this->writeBaselineFrom($this->reportWith([
            'escaped' => [$this->entry('Sample.php', 7, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;')],
        ]));

        // Ten more leading lines push the same statement down the file.
        $this->writeSubject(10);
        self::assertSame('Sample::compute', ScopeIndex::resolve($this->workDir . '/Sample.php', 17));

        $shifted = $this->reportWith([
            'escaped' => [$this->entry('Sample.php', 17, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;')],
        ]);

        self::assertSame(0, $this->check($shifted));
    }

    public function testASecondIdenticalMutantInTheSameMethodIsNotCoveredByTheFirst(): void
    {
        $mutant = $this->entry('Sample.php', 7, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;');

        $this->writeBaselineFrom($this->reportWith(['escaped' => [$mutant]]));

        self::assertSame(1, $this->check($this->reportWith(['escaped' => [$mutant, $mutant]])));
        self::assertStringContainsString('1 mutant(s) escaped', $this->output);
    }

    public function testAReportWithoutMutantsIsAnOperatorErrorRatherThanAPass(): void
    {
        $this->writeBaselineFrom($this->reportWith(['escaped' => []]));

        // A truncated report yields empty sections, which would otherwise pass.
        file_put_contents($this->workDir . '/report.json', json_encode(['stats' => ['totalMutantsCount' => 0]]));

        self::assertSame(2, $this->invoke([
            '--report=' . $this->workDir . '/report.json',
            '--baseline=' . $this->workDir . '/baseline.json',
        ]));
        self::assertStringContainsString('no mutants in', $this->errors);
    }

    public function testContextLinesDoNotTakePartInTheIdentity(): void
    {
        $report = $this->reportWith([
            'escaped' => [$this->entry('Sample.php', 40, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;')],
        ]);
        $this->writeBaselineFrom($report);

        $entry            = $this->entry('Sample.php', 40, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;');
        $entry['diff']    = "@@ @@\n     // a comment that did not exist before\n"
            . "-        return \$a && \$b;\n+        return \$a || \$b;\n     }";
        $withExtraContext = $this->reportWith(['escaped' => [$entry]]);

        self::assertSame(0, $this->check($withExtraContext));
    }

    public function testAnEscapedMutantMissingFromTheBaselineFailsTheGate(): void
    {
        $this->writeBaselineFrom($this->reportWith(['escaped' => []]));

        $report = $this->reportWith([
            'escaped' => [$this->entry('Sample.php', 40, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;')],
        ]);

        self::assertSame(1, $this->check($report));
        self::assertStringContainsString('1 mutant(s) escaped', $this->output);
        self::assertStringContainsString('LogicalAnd', $this->output);
    }

    public function testADifferentMutatorOnTheSameLineIsTreatedAsNew(): void
    {
        $this->writeBaselineFrom($this->reportWith([
            'escaped' => [$this->entry('Sample.php', 40, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;')],
        ]));

        $report = $this->reportWith([
            'escaped' => [$this->entry('Sample.php', 40, 'LogicalAndNegation', '-        return $a && $b;', '+        return !($a && $b);')],
        ]);

        self::assertSame(1, $this->check($report));
    }

    public function testATimeoutedMutantInTheBaselineMayEscapeLater(): void
    {
        $mutant = $this->entry('Sample.php', 40, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;');

        $this->writeBaselineFrom($this->reportWith(['timeouted' => [$mutant]]));

        self::assertSame(0, $this->check($this->reportWith(['escaped' => [$mutant]])));
    }

    public function testAnErroredMutantInTheBaselineMayEscapeLater(): void
    {
        $mutant = $this->entry('Sample.php', 40, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;');

        $this->writeBaselineFrom($this->reportWith(['errored' => [$mutant]]));

        self::assertSame(0, $this->check($this->reportWith(['escaped' => [$mutant]])));
    }

    public function testBaselineEntriesThatNoLongerSurviveAreReportedWithoutFailing(): void
    {
        $this->writeBaselineFrom($this->reportWith([
            'escaped' => [$this->entry('Sample.php', 40, 'LogicalAnd', '-        return $a && $b;', '+        return $a || $b;')],
        ]));

        self::assertSame(0, $this->check($this->reportWith(['escaped' => []])));
        self::assertStringContainsString('1 baseline entries no longer survive', $this->output);
    }

    public function testTheBaselineRecordsEscapedTimeoutedAndErroredMutants(): void
    {
        $this->writeBaselineFrom($this->reportWith([
            'escaped'   => [$this->entry('A.php', 10, 'LogicalAnd', '-a', '+b')],
            'timeouted' => [$this->entry('B.php', 10, 'LogicalAnd', '-c', '+d')],
            'errored'   => [$this->entry('C.php', 10, 'LogicalAnd', '-e', '+f')],
        ]));

        $baseline = json_decode((string) file_get_contents($this->workDir . '/baseline.json'), true);

        self::assertIsArray($baseline);
        self::assertIsArray($baseline['allowed']);
        self::assertCount(3, $baseline['allowed']);
    }

    public function testAMissingReportIsAnOperatorErrorRatherThanAPass(): void
    {
        self::assertSame(2, $this->invoke(['--report=' . $this->workDir . '/absent.json']));
        self::assertStringContainsString('not found', $this->errors);
    }

    public function testAnUnreadableBaselineIsAnOperatorErrorRatherThanAPass(): void
    {
        file_put_contents($this->workDir . '/report.json', json_encode($this->reportWith(['escaped' => []])));
        file_put_contents($this->workDir . '/baseline.json', 'not json at all');

        self::assertSame(2, $this->invoke([
            '--report=' . $this->workDir . '/report.json',
            '--baseline=' . $this->workDir . '/baseline.json',
        ]));
        self::assertStringContainsString('invalid JSON', $this->errors);
    }

    public function testTheEnclosingMethodIsResolvedFromTheLineNumber(): void
    {
        $file = $this->workDir . '/Subject.php';
        file_put_contents($file, <<<'PHP'
            <?php

            final class Outer
            {
                public function first(): int
                {
                    return 1;
                }

                public function second(): int
                {
                    $inner = new class {
                        public function nested(): int
                        {
                            return 2;
                        }
                    };

                    return $inner->nested();
                }
            }
            PHP);

        self::assertSame('Outer::first', ScopeIndex::resolve($file, 7));
        self::assertSame('Outer::second', ScopeIndex::resolve($file, 19));
        self::assertSame('Outer::nested', ScopeIndex::resolve($file, 15));
        self::assertSame('', ScopeIndex::resolve($file, 2));
    }

    public function testInterpolationBracesDoNotDetachLaterMethodsFromTheirClass(): void
    {
        $file = $this->workDir . '/Interpolated.php';
        file_put_contents($file, <<<'PHP'
            <?php

            final class Interpolated
            {
                public function withCurly(string $name): string
                {
                    return "hello {$name}";
                }

                public function withHeredoc(string $name): string
                {
                    return <<<TXT
                        value {$name}
                        TXT;
                }

                public function afterwards(): int
                {
                    return 1;
                }
            }
            PHP);

        // The tokenizer closes an interpolation with a plain '}', so an
        // uncounted opener drifts the depth and drops the class prefix.
        self::assertSame('Interpolated::withCurly', ScopeIndex::resolve($file, 7));
        self::assertSame('Interpolated::withHeredoc', ScopeIndex::resolve($file, 13));
        self::assertSame('Interpolated::afterwards', ScopeIndex::resolve($file, 20));
    }

    public function testInterfaceMethodsHaveNoBodyAndAreNotIndexed(): void
    {
        $file = $this->workDir . '/Contract.php';
        file_put_contents($file, <<<'PHP'
            <?php

            interface Contract
            {
                public function declared(): int;
            }

            function afterwards(): int
            {
                return 3;
            }
            PHP);

        self::assertSame('', ScopeIndex::resolve($file, 5));
        self::assertSame('afterwards', ScopeIndex::resolve($file, 10));
    }

    /**
     * @param  array<string, list<array<string, mixed>>> $sections
     * @return array<string, mixed>
     */
    private function reportWith(array $sections): array
    {
        return array_merge(
            ['stats' => ['totalMutantsCount' => 100], 'escaped' => [], 'timeouted' => [], 'errored' => []],
            $sections,
        );
    }

    /**
     * Writes the subject file the reports point at, padded to move its method down.
     */
    private function writeSubject(int $leadingLines): void
    {
        $padding = str_repeat("// padding\n", $leadingLines);

        file_put_contents($this->workDir . '/Sample.php', "<?php\n\n" . $padding . <<<'PHP'
            final class Sample
            {
                public function compute(bool $a, bool $b): bool
                {
                    return $a && $b;
                }
            }
            PHP);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $file, int $line, string $mutator, string $removed, string $added): array
    {
        return [
            'mutator' => [
                'mutatorName'       => $mutator,
                'originalFilePath'  => $this->workDir . '/' . $file,
                'originalStartLine' => $line,
            ],
            'diff' => "--- Original\n+++ New\n@@ @@\n" . $removed . "\n" . $added,
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeBaselineFrom(array $report): void
    {
        file_put_contents($this->workDir . '/report.json', json_encode($report));

        $code = $this->invoke([
            '--update',
            '--report=' . $this->workDir . '/report.json',
            '--baseline=' . $this->workDir . '/baseline.json',
        ]);

        self::assertSame(0, $code);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function check(array $report): int
    {
        file_put_contents($this->workDir . '/report.json', json_encode($report));

        return $this->invoke([
            '--report=' . $this->workDir . '/report.json',
            '--baseline=' . $this->workDir . '/baseline.json',
        ]);
    }

    /**
     * @param list<string> $arguments
     */
    private function invoke(array $arguments): int
    {
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);

        ob_start();
        $code         = MutationBaseline::run($arguments, $errors);
        $this->output = (string) ob_get_clean();

        rewind($errors);
        $this->errors = (string) stream_get_contents($errors);
        fclose($errors);

        return $code;
    }
}
