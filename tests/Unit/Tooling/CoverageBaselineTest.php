<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use Sloop\Tooling\CoverageBaseline;

final class CoverageBaselineTest extends TestCase
{
    private string $workDir;

    private string $output = '';

    private string $errors = '';

    protected function setUp(): void
    {
        $workDir = tempnam(sys_get_temp_dir(), 'coverage-baseline');
        self::assertIsString($workDir);
        unlink($workDir);
        mkdir($workDir . '/src', 0777, true);

        $this->workDir = $workDir;
    }

    protected function tearDown(): void
    {
        foreach ([$this->workDir . '/src/*', $this->workDir . '/*.*'] as $pattern) {
            $files = glob($pattern);
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
        }

        rmdir($this->workDir . '/src');
        rmdir($this->workDir);
    }

    private function writeSubject(int $leadingLines, int $statements = 1): void
    {
        $padding = str_repeat("// padding\n", $leadingLines);
        $body    = str_repeat("        return \$a && \$b;\n", $statements);

        file_put_contents(
            $this->workDir . '/src/Sample.php',
            "<?php\n\n" . $padding . "final class Sample\n{\n    public function compute(bool \$a, bool \$b): bool\n    {\n"
            . $body . "    }\n}\n",
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $entries
     */
    private function writeList(array $entries): void
    {
        $uncovered = [];
        foreach ($entries as [$file, $scope, $line, $reason]) {
            $uncovered[] = ['file' => $file, 'scope' => $scope, 'line' => $line, 'reason' => $reason];
        }

        file_put_contents($this->workDir . '/list.json', json_encode(['uncovered' => $uncovered]));
    }

    /**
     * @param array<string, list<array{0: int, 1: string, 2: int}>> $files
     */
    private function writeReport(array $files): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<coverage><project>';
        foreach ($files as $file => $lines) {
            $xml .= '<file name="' . $this->workDir . '/' . $file . '">';
            foreach ($lines as [$number, $type, $count]) {
                $xml .= '<line num="' . $number . '" type="' . $type . '" count="' . $count . '"/>';
            }

            $xml .= '</file>';
        }

        file_put_contents($this->workDir . '/clover.xml', $xml . '</project></coverage>');
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return [
            '--report=' . $this->workDir . '/clover.xml',
            '--baseline=' . $this->workDir . '/list.json',
            '--root=' . $this->workDir,
        ];
    }

    private function check(): int
    {
        return $this->invoke($this->paths());
    }

    /**
     * @param list<string> $arguments
     */
    private function invoke(array $arguments): int
    {
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);

        ob_start();
        $code         = CoverageBaseline::run($arguments, $errors);
        $this->output = (string) ob_get_clean();

        rewind($errors);
        $this->errors = (string) stream_get_contents($errors);
        fclose($errors);

        return $code;
    }

    public function testANewUnexecutedLineFailsTheCheck(): void
    {
        $this->writeSubject(0);
        $this->writeList([]);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 0]]]);

        self::assertSame(1, $this->check());
        self::assertStringContainsString('1 line(s) of src are executed by no Unit test and not listed', $this->output);
        self::assertStringContainsString('src/Sample.php:7  Sample::compute  | return $a && $b;', $this->output);
    }

    public function testAListedLinePasses(): void
    {
        $this->writeSubject(0);
        $this->writeList([['src/Sample.php', 'Sample::compute', 'return $a && $b;', 'A reason.']]);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 0]]]);

        self::assertSame(0, $this->check());
        self::assertStringContainsString('No new unexecuted lines (1 unexecuted, all listed).', $this->output);
    }

    public function testLineNumbersDoNotTakePartInTheIdentity(): void
    {
        $this->writeSubject(10);
        $this->writeList([['src/Sample.php', 'Sample::compute', 'return $a && $b;', 'A reason.']]);
        $this->writeReport(['src/Sample.php' => [[17, 'stmt', 0]]]);

        self::assertSame(0, $this->check());
    }

    public function testTheSameTextInAnotherMethodIsNotCoveredByTheListedOne(): void
    {
        $this->writeSubject(0);
        $this->writeList([['src/Sample.php', 'Sample::other', 'return $a && $b;', 'A reason.']]);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 0]]]);

        self::assertSame(1, $this->check());
    }

    public function testASecondIdenticalLineInTheSameMethodIsNotCoveredByTheFirst(): void
    {
        $this->writeSubject(0, 2);
        $this->writeList([['src/Sample.php', 'Sample::compute', 'return $a && $b;', 'A reason.']]);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 0], [8, 'stmt', 0]]]);

        self::assertSame(1, $this->check());
        self::assertStringContainsString('src/Sample.php:8', $this->output);
    }

    public function testExecutedLinesAndLinesThatAreNotStatementsAreIgnored(): void
    {
        $this->writeSubject(0);
        $this->writeList([]);
        $this->writeReport(['src/Sample.php' => [[5, 'method', 0], [7, 'stmt', 3]]]);

        self::assertSame(0, $this->check());
        self::assertStringContainsString('(0 unexecuted, all listed)', $this->output);
    }

    public function testAListedLineThatIsNowExecutedIsReportedWithoutFailing(): void
    {
        $this->writeSubject(0);
        $this->writeList([['src/Sample.php', 'Sample::compute', 'return $a && $b;', 'A reason.']]);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 2]]]);

        self::assertSame(0, $this->check());
        self::assertStringContainsString('1 listed line(s) are now executed by a test', $this->output);
        self::assertStringContainsString('src/Sample.php  Sample::compute  | return $a && $b;', $this->output);
    }

    public function testAnEntryWithoutAReasonIsRefused(): void
    {
        $this->writeSubject(0);
        $this->writeList([['src/Sample.php', 'Sample::compute', 'return $a && $b;', '  ']]);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 0]]]);

        self::assertSame(2, $this->check());
        self::assertStringContainsString('gives no reason', $this->errors);
    }

    public function testUpdateKeepsTheReasonOfALineStillUnexecutedAndLeavesANewOneWithoutAReason(): void
    {
        $this->writeSubject(0, 2);
        $this->writeList([['src/Sample.php', 'Sample::compute', 'return $a && $b;', 'Kept reason.']]);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 0], [8, 'stmt', 0]]]);

        self::assertSame(0, $this->invoke(['--update', ...$this->paths()]));
        self::assertStringContainsString('1 entry(ies) have no reason yet', $this->output);

        $list = json_decode((string) file_get_contents($this->workDir . '/list.json'), true);
        self::assertSame(
            ['uncovered' => [
                ['file' => 'src/Sample.php', 'scope' => 'Sample::compute', 'line' => 'return $a && $b;', 'reason' => 'Kept reason.'],
                ['file' => 'src/Sample.php', 'scope' => 'Sample::compute', 'line' => 'return $a && $b;', 'reason' => ''],
            ]],
            $list,
        );

        self::assertSame(2, $this->check());
    }

    public function testFilesOutsideSrcAreIgnored(): void
    {
        $this->writeSubject(0);
        $this->writeList([]);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 1]], 'vendor/Other.php' => [[3, 'stmt', 0]]]);

        self::assertSame(0, $this->check());
    }

    public function testAReportWithoutAnyFileOfSrcIsAnOperatorError(): void
    {
        $this->writeList([]);
        $this->writeReport(['vendor/Other.php' => [[3, 'stmt', 0]]]);

        self::assertSame(2, $this->check());
        self::assertStringContainsString('no file of', $this->errors);
    }

    public function testAMissingReportIsAnOperatorError(): void
    {
        $this->writeList([]);

        self::assertSame(2, $this->check());
        self::assertStringContainsString('not found', $this->errors);
    }

    public function testAReportThatIsNotXmlIsAnOperatorError(): void
    {
        $this->writeList([]);
        file_put_contents($this->workDir . '/clover.xml', 'not xml');

        self::assertSame(2, $this->check());
        self::assertStringContainsString('invalid XML', $this->errors);
    }

    public function testAMissingListIsAnOperatorError(): void
    {
        $this->writeSubject(0);
        $this->writeReport(['src/Sample.php' => [[7, 'stmt', 0]]]);

        self::assertSame(2, $this->check());
        self::assertStringContainsString('Create it once with', $this->errors);
    }

    public function testAnUnknownArgumentIsAnOperatorError(): void
    {
        self::assertSame(2, $this->invoke(['--bogus']));
        self::assertStringContainsString('unknown argument: --bogus', $this->errors);
    }
}
