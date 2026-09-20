<?php

declare(strict_types=1);

namespace Sloop\Validation;

use IntlException;
use InvalidArgumentException;
use LogicException;
use MessageFormatter;
use RuntimeException;

/**
 * Default validation messages and the application's overrides of them.
 *
 * The framework's messages live in `lang/en/validation.php` at the package
 * root (rule name => message). An application overrides them key by key with
 * its own `lang/en/validation.php`, loaded once at boot by the Application;
 * rules it does not list keep the framework's message. Without load() (a
 * Validator used on its own, or in a test) only the framework's messages apply.
 *
 * Messages are ICU MessageFormat patterns: `{label}` is the field's label and
 * the other placeholders are the rule's parameters. A single quote directly
 * before `{` is rejected, because ICU would print the placeholder literally;
 * quote a value with `"{value}"` or `「{value}」` instead.
 */
final class ValidationMessages
{
    /**
     * Locale used to format messages.
     *
     * @var string
     */
    private const string LOCALE = 'en';

    /**
     * The framework's messages, read on first use.
     *
     * @var array<string, string>|null
     */
    private static ?array $defaults = null;

    /**
     * The application's messages, keyed by rule name.
     *
     * @var array<string, string>
     */
    private static array $overrides = [];

    /**
     * Whether load() has run.
     *
     * @var bool
     */
    private static bool $loaded = false;

    /**
     * Load the application's overrides from `<langPath>/en/validation.php`, if the file exists.
     *
     * @param  string                   $langPath Absolute path to the application's lang directory
     * @return void
     * @throws LogicException           If already loaded
     * @throws InvalidArgumentException If the file does not return a map of known rule names to valid message patterns
     */
    public static function load(string $langPath): void
    {
        if (self::$loaded) {
            throw new LogicException('Validation messages have already been loaded.');
        }

        $file = $langPath . \DIRECTORY_SEPARATOR . 'en' . \DIRECTORY_SEPARATOR . 'validation.php';
        if (is_file($file)) {
            $messages = self::readFile($file);
            $defaults = self::defaults();
            foreach (array_keys($messages) as $rule) {
                if (!isset($defaults[$rule])) {
                    throw new InvalidArgumentException('Unknown validation rule "' . $rule . '" in ' . $file . '.');
                }
            }
            self::$overrides = $messages;
        }

        self::$loaded = true;
    }

    /**
     * Forget the loaded overrides (for tests).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$overrides = [];
        self::$loaded    = false;
    }

    /**
     * The message pattern for a rule: the application's if it has one, the framework's otherwise.
     *
     * @param  string         $rule Rule name
     * @return string
     * @throws LogicException When no message exists for the rule
     */
    public static function get(string $rule): string
    {
        return self::$overrides[$rule]
            ?? self::defaults()[$rule]
            ?? throw new LogicException('No validation message for rule "' . $rule . '".');
    }

    /**
     * Fill the placeholders of a message pattern.
     *
     * Lists are joined with ", ".
     *
     * @param  string                                                 $pattern Message pattern
     * @param  array<string, int|float|string|list<int|float|string>> $args    Values keyed by placeholder name
     * @return string
     * @throws RuntimeException                                       When ICU cannot format the pattern
     */
    public static function format(string $pattern, array $args): string
    {
        $values = [];
        foreach ($args as $name => $arg) {
            $values[$name] = \is_array($arg) ? implode(', ', $arg) : $arg;
        }

        $message = MessageFormatter::formatMessage(self::LOCALE, $pattern, $values);
        if ($message === false) {
            throw new RuntimeException('Could not format validation message "' . $pattern . '": ' . intl_get_error_message());
        }

        return $message;
    }

    /**
     * Reject a message pattern that ICU cannot parse or that quotes a placeholder with `'`.
     *
     * @param  string                   $pattern Message pattern
     * @return void
     * @throws InvalidArgumentException When the pattern is rejected
     */
    public static function assertValidPattern(string $pattern): void
    {
        if (str_contains($pattern, "'{")) {
            throw new InvalidArgumentException(
                'Validation message "' . $pattern . '" puts a single quote before "{", which ICU prints literally. '
                . 'Quote the value with "{value}" or 「{value}」 instead.',
            );
        }

        try {
            $formatter = MessageFormatter::create(self::LOCALE, $pattern);
        } catch (IntlException) {
            $formatter = null;
        }
        if ($formatter === null) {
            throw new InvalidArgumentException('Validation message "' . $pattern . '" is not a valid ICU message pattern.');
        }
    }

    /**
     * The framework's messages, read from the package's lang directory on first use.
     *
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        return self::$defaults ??= self::readFile(\dirname(__DIR__, 3) . '/lang/en/validation.php');
    }

    /**
     * Read a message file.
     *
     * @param  string                   $file Absolute path to a PHP file returning rule name => message
     * @return array<string, string>
     * @throws InvalidArgumentException When the file does not return a map of strings to valid message patterns
     */
    private static function readFile(string $file): array
    {
        $contents = require $file;
        if (!\is_array($contents)) {
            throw new InvalidArgumentException('Validation message file ' . $file . ' must return an array.');
        }

        $messages = [];
        foreach ($contents as $rule => $message) {
            if (!\is_string($rule) || !\is_string($message)) {
                throw new InvalidArgumentException('Validation message file ' . $file . ' must map rule names to strings.');
            }
            self::assertValidPattern($message);
            $messages[$rule] = $message;
        }

        return $messages;
    }
}
