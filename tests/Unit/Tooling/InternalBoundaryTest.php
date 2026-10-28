<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Sloop\Database\Migration\Migration;
use Sloop\Database\Query\Grammar;
use Sloop\Http\Controller\Controller;
use Sloop\Http\Request\ValidatedRequest;
use SplFileInfo;

final class InternalBoundaryTest extends TestCase
{
    private const string SRC_DIR = __DIR__ . '/../../../src/Sloop/';

    // Classes the docs tell users to subclass.
    private const array EXTENSION_POINTS = [Controller::class, Grammar::class, Migration::class, ValidatedRequest::class];

    /**
     * @return list<ReflectionClass<object>>
     */
    private function sourceClasses(): array
    {
        $root     = realpath(self::SRC_DIR);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::SRC_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        self::assertIsString($root);

        $classes = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getRealPath();
            // Function files are lowercase; only PascalCase files hold a class.
            if ($path === false || preg_match('/^[A-Z]/', $file->getFilename()) !== 1) {
                continue;
            }
            $name = 'Sloop\\' . str_replace('/', '\\', substr($path, \strlen($root) + 1, -4));
            if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
                self::fail('No type named ' . $name . ' in ' . $path);
            }
            $classes[] = new ReflectionClass($name);
        }
        usort($classes, static fn (ReflectionClass $a, ReflectionClass $b): int => strcmp($a->getName(), $b->getName()));

        return $classes;
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $reflection
     */
    private function isInternal(ReflectionClass|ReflectionMethod $reflection): bool
    {
        $doc = $reflection->getDocComment();

        return $doc !== false && preg_match('/^\s*(?:\/\*\*|\*)\s*@internal\b/m', $doc) === 1;
    }

    /**
     * Public methods a user can call on a type the framework does not mark internal.
     *
     * @return list<array{ReflectionClass<object>, ReflectionMethod}>
     */
    private function publicSurface(): array
    {
        $surface = [];
        foreach ($this->sourceClasses() as $class) {
            if ($class->isTrait() || $this->isInternal($class)) {
                continue;
            }
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$this->isInternal($method)) {
                    $surface[] = [$class, $method];
                }
            }
        }

        return $surface;
    }

    /**
     * @return list<string>
     */
    private function classNamesIn(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $inner) {
                $names = [...$names, ...$this->classNamesIn($inner)];
            }

            return $names;
        }

        return [];
    }

    private function namesInternalType(string $name): bool
    {
        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            return false;
        }

        return $this->isInternal(new ReflectionClass($name));
    }

    /**
     * @param  ReflectionClass<object> $class
     * @return list<string>
     */
    private function internalTypesOnSurface(ReflectionClass $class): array
    {
        $extended       = \in_array($class->getName(), self::EXTENSION_POINTS, true);
        $methodFilter   = $extended ? ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED : ReflectionMethod::IS_PUBLIC;
        $propertyFilter = $extended ? ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED : ReflectionProperty::IS_PUBLIC;

        $found = [];
        foreach ($class->getMethods($methodFilter) as $method) {
            if ($this->isInternal($method)) {
                continue;
            }
            $names = $this->classNamesIn($method->getReturnType());
            foreach ($method->getParameters() as $parameter) {
                $names = [...$names, ...$this->classNamesIn($parameter->getType())];
            }
            foreach (array_filter($names, $this->namesInternalType(...)) as $name) {
                $found[] = $class->getName() . '::' . $method->getName() . '() names ' . $name;
            }
        }
        foreach ($class->getProperties($propertyFilter) as $property) {
            foreach (array_filter($this->classNamesIn($property->getType()), $this->namesInternalType(...)) as $name) {
                $found[] = $class->getName() . '::$' . $property->getName() . ' names ' . $name;
            }
        }

        return $found;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function isExtendableValidationClass(ReflectionClass $class): bool
    {
        return str_starts_with($class->getName(), 'Sloop\\Validation\\')
            && !$class->isFinal() && !$class->isInterface() && !$class->isTrait() && !$class->isEnum()
            && !$this->isInternal($class);
    }

    /**
     * @param  ReflectionClass<object> $class
     * @return list<string>
     */
    private function sealedNames(ReflectionClass $class): array
    {
        $doc = $class->getDocComment();
        if ($doc === false || preg_match('/^\s*\*\s*@phpstan-sealed\s+(\S+)/m', $doc, $match) !== 1) {
            return [];
        }
        $names = explode('|', $match[1]);
        sort($names);

        return $names;
    }

    /**
     * @param  ReflectionClass<object>       $class
     * @param  list<ReflectionClass<object>> $sources
     * @return list<string>
     */
    private function directSubclassNames(ReflectionClass $class, array $sources): array
    {
        $names = [];
        foreach ($sources as $candidate) {
            $parent = $candidate->getParentClass();
            if ($parent !== false && $parent->getName() === $class->getName()) {
                $names[] = $candidate->getShortName();
            }
        }
        sort($names);

        return $names;
    }

    public function testPublicMethodsAreNotDeclaredOnInternalClasses(): void
    {
        // PHPStan reports a call to a method whose declaring class is internal
        // (method.internalClass in bleedingEdge), even when the call goes
        // through a public subclass. A trait's methods count as the using
        // class's, so an internal trait does not trip it.
        $offenders = [];
        foreach ($this->publicSurface() as [$class, $method]) {
            $declaring = $method->getDeclaringClass();
            if ($this->isInternal($declaring)) {
                $offenders[] = $class->getName() . '::' . $method->getName() . ' (declared on ' . $declaring->getName() . ')';
            }
        }

        self::assertSame([], $offenders);
    }

    public function testPublicSurfaceDoesNotNameInternalTypes(): void
    {
        // A user who calls a method, reads a property or overrides a method of
        // a class that is not internal has to name the types written there.
        // Protected methods and properties count on the classes the docs tell
        // users to extend.
        $offenders = [];
        foreach ($this->sourceClasses() as $class) {
            if (!$class->isTrait() && !$this->isInternal($class)) {
                $offenders = [...$offenders, ...$this->internalTypesOnSurface($class)];
            }
        }

        self::assertSame([], array_values(array_unique($offenders)));
    }

    public function testValidationBaseClassesAreSealedToTheirSubclasses(): void
    {
        // Users add rules with using(), not by subclassing a rule type.
        // @phpstan-sealed reports a subclass the list leaves out, so it also
        // catches a framework type added without updating the list; this test
        // catches the other way, a name left in the list after its class stops
        // extending the base. A class with no subclass has nothing to seal it
        // to, so it fails here too and should be final instead.
        $sources    = $this->sourceClasses();
        $mismatches = [];
        foreach ($sources as $class) {
            if (!$this->isExtendableValidationClass($class)) {
                continue;
            }
            $sealed   = $this->sealedNames($class);
            $children = $this->directSubclassNames($class, $sources);
            if ($sealed === [] || $sealed !== $children) {
                $mismatches[$class->getName()] = ['sealed' => $sealed, 'subclasses' => $children];
            }
        }

        self::assertSame([], $mismatches);
    }
}
