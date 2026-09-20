<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sloop\Tests\Support\ThrowsAssertions;
use Sloop\Validation\Rule;
use Sloop\Validation\ValidationMessages;
use Sloop\Validation\Validator;

final class ValidationMessagesTest extends TestCase
{
    use ThrowsAssertions;

    protected function setUp(): void
    {
        ValidationMessages::reset();
    }

    protected function tearDown(): void
    {
        ValidationMessages::reset();
    }

    /**
     * The message file the loader reads under a lang directory, with this platform's separator.
     */
    private static function messageFile(string $langPath): string
    {
        return $langPath . \DIRECTORY_SEPARATOR . 'en' . \DIRECTORY_SEPARATOR . 'validation.php';
    }

    public function testFrameworkMessagesAreUsedWithoutLoad(): void
    {
        $this->assertSame('The {label} field is required.', ValidationMessages::get('required'));
    }

    public function testEveryFrameworkMessageIsAValidPattern(): void
    {
        $messages = require \dirname(__DIR__, 3) . '/lang/en/validation.php';
        $this->assertIsArray($messages);

        foreach ($messages as $rule => $message) {
            $this->assertIsString($rule);
            $this->assertIsString($message);
            ValidationMessages::assertValidPattern($message);
            $this->assertSame($message, ValidationMessages::get($rule));
        }
    }

    public function testApplicationFileOverridesKeyByKey(): void
    {
        ValidationMessages::load(__DIR__ . '/fixtures/lang');

        $this->assertSame('{label} を入力してください。', ValidationMessages::get('required'));
        $this->assertSame('The {label} field must be an integer.', ValidationMessages::get('int'));
    }

    public function testOverrideIsUsedByTheValidator(): void
    {
        ValidationMessages::load(__DIR__ . '/fixtures/lang');

        $errors = new Validator(['name' => Rule::string()->required()->label('名前')])->validate([])->errors();

        $this->assertSame('名前 を入力してください。', $errors['name'][0]->message);
    }

    public function testMissingApplicationFileKeepsFrameworkMessages(): void
    {
        ValidationMessages::load(__DIR__ . '/fixtures');

        $this->assertSame('The {label} field is required.', ValidationMessages::get('required'));
    }

    public function testSecondLoadThrows(): void
    {
        ValidationMessages::load(__DIR__ . '/fixtures');

        $e = $this->assertThrows(LogicException::class, static fn () => ValidationMessages::load(__DIR__ . '/fixtures/lang'));

        $this->assertSame('Validation messages have already been loaded.', $e->getMessage());
    }

    public function testResetForgetsOverrides(): void
    {
        ValidationMessages::load(__DIR__ . '/fixtures/lang');
        ValidationMessages::reset();

        $this->assertSame('The {label} field is required.', ValidationMessages::get('required'));
        ValidationMessages::load(__DIR__ . '/fixtures/lang');
        $this->assertSame('{label} を入力してください。', ValidationMessages::get('required'));
    }

    public function testUnknownRuleInApplicationFileThrows(): void
    {
        $path = __DIR__ . '/fixtures/unknown';

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => ValidationMessages::load($path));

        $this->assertSame('Unknown validation rule "minLen" in ' . self::messageFile($path) . '.', $e->getMessage());
    }

    public function testFileNotReturningAnArrayThrows(): void
    {
        $path = __DIR__ . '/fixtures/notarray';

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => ValidationMessages::load($path));

        $this->assertSame('Validation message file ' . self::messageFile($path) . ' must return an array.', $e->getMessage());
    }

    public function testFileWithNonStringMessageThrows(): void
    {
        $path = __DIR__ . '/fixtures/nonstring';

        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => ValidationMessages::load($path));

        $this->assertSame('Validation message file ' . self::messageFile($path) . ' must map rule names to strings.', $e->getMessage());
    }

    public function testFileWithQuotedPlaceholderThrows(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => ValidationMessages::load(__DIR__ . '/fixtures/badpattern'));

        $this->assertStringContainsString('puts a single quote before "{"', $e->getMessage());
    }

    public function testFailedLoadLeavesNothingLoaded(): void
    {
        $this->assertThrows(InvalidArgumentException::class, static fn () => ValidationMessages::load(__DIR__ . '/fixtures/unknown'));

        ValidationMessages::load(__DIR__ . '/fixtures/lang');
        $this->assertSame('{label} を入力してください。', ValidationMessages::get('required'));
    }

    public function testUnknownRuleHasNoMessage(): void
    {
        $e = $this->assertThrows(LogicException::class, static fn () => ValidationMessages::get('nope'));

        $this->assertSame('No validation message for rule "nope".', $e->getMessage());
    }

    public function testMalformedPatternIsRejected(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => ValidationMessages::assertValidPattern('{label'));

        $this->assertSame('Validation message "{label" is not a valid ICU message pattern.', $e->getMessage());
    }

    public function testMalformedPatternIsRejectedWhenIntlThrows(): void
    {
        $previous = \ini_get('intl.use_exceptions');
        ini_set('intl.use_exceptions', '1');
        try {
            $this->assertThrows(InvalidArgumentException::class, static fn () => ValidationMessages::assertValidPattern('{label'));
        } finally {
            ini_set('intl.use_exceptions', (string) $previous);
        }
    }

    public function testQuotedPlaceholderMessageNamesTheAlternatives(): void
    {
        $e = $this->assertThrows(InvalidArgumentException::class, static fn () => ValidationMessages::assertValidPattern("x '{v}'"));

        $this->assertSame(
            'Validation message "x \'{v}\'" puts a single quote before "{", which ICU prints literally. '
            . 'Quote the value with "{value}" or 「{value}」 instead.',
            $e->getMessage(),
        );
    }

    public function testFormatJoinsListsAndKeepsFloatDigits(): void
    {
        $this->assertSame(
            'a, b / 1, 2 / 0.0001 / 3',
            ValidationMessages::format('{list} / {ints} / {float} / {int}', ['list' => ['a', 'b'], 'ints' => [1, 2], 'float' => 0.0001, 'int' => 3]),
        );
    }

    public function testFormatFailureThrows(): void
    {
        $e = $this->assertThrows(RuntimeException::class, static fn () => ValidationMessages::format('{x', []));

        $this->assertMatchesRegularExpression('/\ACould not format validation message "\{x": \S/', $e->getMessage());
    }
}
