<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use NoDiscard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Sloop\Database\Query\WindowExpression;
use Sloop\Support\Collection;
use Sloop\Validation\FieldRule;
use Sloop\Validation\ListRule;
use Sloop\Validation\StringRule;
use Sloop\Validation\TimeRule;
use Sloop\Validation\Validator;

final class NoDiscardTest extends TestCase
{
    private const string VALIDATION_DIR = __DIR__ . '/../../../src/Sloop/Validation/';

    /**
     * Classes whose methods hand back a changed copy and leave the original as it was.
     *
     * @return list<class-string>
     */
    private function copyingClasses(): array
    {
        $classes = [WindowExpression::class];
        $files   = glob(self::VALIDATION_DIR . '*.php');
        self::assertIsArray($files);

        foreach ($files as $file) {
            $name = 'Sloop\\Validation\\' . basename($file, '.php');
            if (class_exists($name) && is_a($name, FieldRule::class, true)) {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    private function returnsCopy(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return !$method->isStatic()
            && !$method->isConstructor()
            && $type instanceof ReflectionNamedType
            && \in_array($type->getName(), ['static', 'self'], true);
    }

    /**
     * @param  class-string $class
     * @return list<string>
     */
    private function unmarked(string $class): array
    {
        $missing = [];
        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->returnsCopy($method) && $method->getAttributes(NoDiscard::class) === []) {
                $missing[] = $class . '::' . $method->getName() . '()';
            }
        }

        return $missing;
    }

    public function testEveryMethodThatReturnsAChangedRuleOrWindowIsMarked(): void
    {
        $missing = [];
        foreach ($this->copyingClasses() as $class) {
            $missing = [...$missing, ...$this->unmarked($class)];
        }

        self::assertSame([], $missing);
    }

    public function testTheValidationRuleClassesAreFound(): void
    {
        $classes = $this->copyingClasses();

        self::assertContains(StringRule::class, $classes);
        self::assertContains(ListRule::class, $classes);
        self::assertContains(TimeRule::class, $classes);
    }

    public function testTheMethodsSharingANameWithASelectMethodAreMarked(): void
    {
        foreach ([[WindowExpression::class, 'orderBy'], [Validator::class, 'with'], [Collection::class, 'groupBy']] as [$class, $name]) {
            $attributes = new ReflectionMethod($class, $name)->getAttributes(NoDiscard::class);

            self::assertCount(1, $attributes, $class . '::' . $name . '()');
        }
    }

    public function testTheMessageNamesTheMethodAndSaysTheOriginalIsKept(): void
    {
        $attribute = new ReflectionMethod(WindowExpression::class, 'orderBy')->getAttributes(NoDiscard::class)[0];

        self::assertSame(
            'WindowExpression::orderBy() returns a new window. The original is not modified.',
            $attribute->newInstance()->message,
        );
    }

    public function testCollectionTransformsAreLeftUnmarked(): void
    {
        self::assertSame([], new ReflectionMethod(Collection::class, 'map')->getAttributes(NoDiscard::class));
    }
}
