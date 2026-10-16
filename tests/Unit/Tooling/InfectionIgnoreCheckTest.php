<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use Sloop\Tooling\IgnoreCheck;
use Sloop\Tooling\IgnoreEntries;
use Sloop\Tooling\MethodSpans;
use Sloop\Tooling\ReportedMutant;

final class InfectionIgnoreCheckTest extends TestCase
{
    private const string SOURCE = <<<'PHP'
        <?php

        namespace App;

        final class Sample
        {
            private int $count = 0;

            public function plain(): int
            {
                return 1 + 1;
            }

            protected function withDefault(
                int $limit = 10,
            ): int {
                $closure = static fn (int $x): int => $x + 1;

                return $closure($limit);
            }
        }
        PHP;

    private string $rootDir;

    protected function setUp(): void
    {
        $rootDir = tempnam(sys_get_temp_dir(), 'ignore-check');
        self::assertIsString($rootDir);
        unlink($rootDir);
        mkdir($rootDir . '/src/App', 0o777, true);
        file_put_contents($rootDir . '/src/App/Sample.php', self::SOURCE);

        $this->rootDir = $rootDir;
    }

    protected function tearDown(): void
    {
        unlink($this->rootDir . '/src/App/Sample.php');
        rmdir($this->rootDir . '/src/App');
        rmdir($this->rootDir . '/src');
        rmdir($this->rootDir);
    }

    private static function mutant(int $line, string $mutator, bool $killed, string $diff = 'd'): ReportedMutant
    {
        return new ReportedMutant('src/App/Sample.php', $line, $mutator, $diff, $killed);
    }

    public function testEntriesAreReadPerMutatorWithTheGlobalListApart(): void
    {
        $entries = IgnoreEntries::fromMutators([
            '@default'      => true,
            'Plus'          => ['ignore' => ['App\Sample::plain::11']],
            'Minus'         => true,
            'ReturnRemoval' => ['ignore' => ['App\Sample', 42]],
            'global-ignore' => ['App\Other::*'],
        ]);

        $this->assertSame(['Plus' => ['App\Sample::plain::11'], 'ReturnRemoval' => ['App\Sample']], $entries->byMutator);
        $this->assertSame(['App\Other::*'], $entries->global);
        $this->assertSame([['Plus', 'App\Sample::plain::11'], ['global-ignore', 'App\Other::*']], $entries->forMutator('Plus'));
        $this->assertSame(['App\Sample', 'App\Other'], $entries->classPatterns());
    }

    public function testTheRunWithoutEntriesDropsEveryIgnoreAndTheMsiFloor(): void
    {
        $config = [
            'minMsi'        => 99,
            'minCoveredMsi' => 99,
            'logs'          => ['text' => 'build/infection/infection.log'],
            'mutators'      => [
                '@default'      => true,
                'Plus'          => ['ignore' => ['App\Sample::plain']],
                'Concat'        => ['ignore' => ['App\Sample'], 'settings' => ['x' => 1]],
                'global-ignore' => ['App\Other'],
            ],
        ];

        $without = IgnoreCheck::configWithout($config, true);

        $this->assertArrayNotHasKey('minMsi', $without);
        $this->assertArrayNotHasKey('minCoveredMsi', $without);
        $this->assertSame(['json' => 'build/infection-ignore-check/without.json'], $without['logs']);
        $this->assertSame(['@default' => true, 'Plus' => true, 'Concat' => ['settings' => ['x' => 1]]], $without['mutators']);
    }

    public function testTheRunWithEntriesKeepsThemAndOnlyMovesTheReport(): void
    {
        $config = ['minMsi' => 99, 'mutators' => ['Plus' => ['ignore' => ['App\Sample::plain']]]];

        $with = IgnoreCheck::configWithout($config, false);

        $this->assertArrayNotHasKey('minMsi', $with);
        $this->assertSame(['json' => 'build/infection-ignore-check/with.json'], $with['logs']);
        $this->assertSame(['Plus' => ['ignore' => ['App\Sample::plain']]], $with['mutators']);
    }

    public function testAMethodSpansItsSignatureAndBodyButNotThePropertiesOrAClosureName(): void
    {
        $file = $this->rootDir . '/src/App/Sample.php';

        $this->assertNull(MethodSpans::methodAt($file, 7), 'property');
        $this->assertSame('plain', MethodSpans::methodAt($file, 9), 'signature line');
        $this->assertSame('plain', MethodSpans::methodAt($file, 11), 'body');
        $this->assertSame('withDefault', MethodSpans::methodAt($file, 15), 'parameter default in a multi-line signature');
        $this->assertSame('withDefault', MethodSpans::methodAt($file, 17), 'arrow function inside the method');
    }

    public function testAHiddenMutantThatATestKillsIsReportedUnderItsEntry(): void
    {
        $entries = IgnoreEntries::fromMutators(['Plus' => ['ignore' => ['App\Sample::plain::11']]]);
        $without = [self::mutant(11, 'Plus', true)];

        $result = IgnoreCheck::evaluate($entries, [], $without, $this->rootDir);

        $this->assertSame(['Plus App\Sample::plain::11'], array_keys($result['killed']));
        $this->assertSame([], $result['empty']);
        $this->assertSame([], $result['unassigned']);
    }

    public function testAHiddenMutantThatSurvivesPassesAndAnEntryThatHidesNothingIsEmpty(): void
    {
        $entries = IgnoreEntries::fromMutators([
            'Plus'          => ['ignore' => ['App\Sample::plain']],
            'ReturnRemoval' => ['ignore' => ['App\Sample::withDefault']],
        ]);
        $without = [self::mutant(11, 'Plus', false)];

        $result = IgnoreCheck::evaluate($entries, [], $without, $this->rootDir);

        $this->assertSame([], $result['killed']);
        $this->assertSame(['ReturnRemoval App\Sample::withDefault'], $result['empty']);
    }

    public function testOnlyMutantsMissingFromTheRunWithEntriesCountAsHiddenCountedAsAMultiset(): void
    {
        $entries = IgnoreEntries::fromMutators(['Plus' => ['ignore' => ['App\Sample::plain']]]);
        $with    = [self::mutant(11, 'Plus', false)];
        $without = [self::mutant(11, 'Plus', false), self::mutant(11, 'Plus', true), self::mutant(11, 'Plus', true, 'other')];

        $result = IgnoreCheck::evaluate($entries, $with, $without, $this->rootDir);

        $this->assertCount(2, $result['killed']['Plus App\Sample::plain']);
    }

    public function testAClassEntryAndASignatureLineAreMatchedLikeInfectionMatchesThem(): void
    {
        $entries = IgnoreEntries::fromMutators([
            'DecrementInteger' => ['ignore' => ['App\Sample::withDefault::15']],
            'Concat'           => ['ignore' => ['App\Sample']],
        ]);
        $without = [self::mutant(15, 'DecrementInteger', false), self::mutant(11, 'Concat', false)];

        $result = IgnoreCheck::evaluate($entries, [], $without, $this->rootDir);

        $this->assertSame([], $result['unassigned']);
        $this->assertSame([], $result['empty']);
    }

    public function testAHiddenMutantNoEntryMatchesIsReportedAsUnassigned(): void
    {
        $entries = IgnoreEntries::fromMutators(['Plus' => ['ignore' => ['App\Sample::plain']]]);
        $without = [self::mutant(11, 'Minus', true)];

        $result = IgnoreCheck::evaluate($entries, [], $without, $this->rootDir);

        $this->assertCount(1, $result['unassigned']);
        $this->assertSame([], $result['killed']);
    }

    public function testTheReportKeepsOnlyMutantsInTheGivenFilesAndMarksTheKilledOnes(): void
    {
        $entry  = static fn (string $file, int $line): array => [
            'mutator' => ['originalFilePath' => '/root/' . $file, 'originalStartLine' => $line, 'mutatorName' => 'Plus'],
            'diff'    => 'd',
        ];
        $report = [
            'killed'    => [$entry('src/A.php', 3), $entry('src/B.php', 4)],
            'escaped'   => [$entry('src/A.php', 5)],
            'timeouted' => [$entry('src/A.php', 6)],
        ];

        $mutants = ReportedMutant::fromReport($report, '/root', ['src/A.php']);

        $this->assertSame(
            [['src/A.php', 3, true], ['src/A.php', 5, false], ['src/A.php', 6, false]],
            array_map(static fn (ReportedMutant $m): array => [$m->file, $m->line, $m->killed], $mutants),
        );
    }
}
