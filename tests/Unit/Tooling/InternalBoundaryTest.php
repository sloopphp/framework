<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

final class InternalBoundaryTest extends TestCase
{
    private const string SRC_DIR = __DIR__ . '/../../../src/Sloop/';

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

        self::assertSame([], array_values(array_unique($offenders)));
    }
}
