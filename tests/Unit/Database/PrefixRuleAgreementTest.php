<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sloop\Database\Config\ConnectionConfigResolver;
use Sloop\Database\Exception\InvalidConfigException;
use Sloop\Database\Query\Grammar;

final class PrefixRuleAgreementTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function providePrefixes(): array
    {
        return [
            'empty'             => ['', true],
            'trailing separator' => ['app_', true],
            'digits'            => ['app1_', true],
            'uppercase'         => ['App_', true],
            'dot'               => ['app.', false],
            'backtick'          => ['app`', false],
            'space'             => ['app ', false],
            'semicolon'         => ['app;', false],
            'trailing newline'  => ["app_\n", false],
            'only newline'      => ["\n", false],
            'non ascii'         => ['アプリ', false],
        ];
    }

    private function grammarAccepts(string $prefix): bool
    {
        try {
            new Grammar($prefix);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function configAccepts(string $prefix): bool
    {
        try {
            ConnectionConfigResolver::validatePool('mydb', [
                'driver'   => 'mysql',
                'host'     => 'primary.example.com',
                'database' => 'app',
                'prefix'   => $prefix,
            ]);
        } catch (InvalidConfigException) {
            return false;
        }

        return true;
    }

    public function testGrammarAndConfigAgreeOnEveryAsciiCharacter(): void
    {
        // The table above is a sample, so it only catches drift that happens to
        // land on one of its values: widening one side to accept '-' left it
        // green. Sweeping the whole ASCII range makes any change to either
        // character class show up here.
        $disagreements = [];

        for ($byte = 0; $byte < 128; $byte++) {
            $prefix = \chr($byte);
            if ($this->grammarAccepts($prefix) !== $this->configAccepts($prefix)) {
                $disagreements[] = \sprintf('0x%02X', $byte);
            }
        }

        $this->assertSame([], $disagreements, 'Bytes where the two prefix rules disagree');
    }

    #[DataProvider('providePrefixes')]
    public function testGrammarAndConfigApplyTheSamePrefixRule(string $prefix, bool $expectedAccepted): void
    {
        // The rule is written twice on purpose: a Grammar can be built without
        // going through the config layer. Two literals can drift apart, so the
        // two are held to the same answer here.
        $this->assertSame(
            $expectedAccepted,
            $this->grammarAccepts($prefix),
            'Grammar disagreed on prefix ' . var_export($prefix, true),
        );
        $this->assertSame(
            $expectedAccepted,
            $this->configAccepts($prefix),
            'ConnectionConfigResolver disagreed on prefix ' . var_export($prefix, true),
        );
    }
}
