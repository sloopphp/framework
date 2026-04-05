<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sloop\Container\Container;
use Sloop\Container\ContainerException;
use Sloop\Container\EntryNotFoundException;

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ---------------------------------------------------------------
    // PSR-11 compliance
    // ---------------------------------------------------------------

    public function testImplementsContainerInterface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    // ---------------------------------------------------------------
    // bind
    // ---------------------------------------------------------------

    public function testBindResolvesClassName(): void
    {
        $this->container->bind(SimpleInterface::class, SimpleClass::class);

        $result = $this->container->get(SimpleInterface::class);

        $this->assertInstanceOf(SimpleClass::class, $result);
    }

    public function testBindReturnsNewInstanceEachTime(): void
    {
        $this->container->bind(SimpleInterface::class, SimpleClass::class);

        $a = $this->container->get(SimpleInterface::class);
        $b = $this->container->get(SimpleInterface::class);

        $this->assertNotSame($a, $b);
    }

    public function testBindResolvesClosure(): void
    {
        $this->container->bind(SimpleInterface::class, function () {
            return new SimpleClass();
        });

        $result = $this->container->get(SimpleInterface::class);

        $this->assertInstanceOf(SimpleClass::class, $result);
    }

    public function testBindClosureReceivesContainer(): void
    {
        $obj = new SimpleClass();
        $this->container->instance(SimpleInterface::class, $obj);
        $this->container->bind('resolved', function (Container $c) {
            return $c->get(SimpleInterface::class);
        });

        $this->assertSame($obj, $this->container->get('resolved'));
    }

    // ---------------------------------------------------------------
    // singleton
    // ---------------------------------------------------------------

    public function testSingletonReturnsSameInstance(): void
    {
        $this->container->singleton(SimpleInterface::class, SimpleClass::class);

        $a = $this->container->get(SimpleInterface::class);
        $b = $this->container->get(SimpleInterface::class);

        $this->assertSame($a, $b);
    }

    public function testSingletonWithClosureReturnsSameInstance(): void
    {
        $this->container->singleton(SimpleInterface::class, function () {
            return new SimpleClass();
        });

        $a = $this->container->get(SimpleInterface::class);
        $b = $this->container->get(SimpleInterface::class);

        $this->assertSame($a, $b);
    }

    // ---------------------------------------------------------------
    // instance
    // ---------------------------------------------------------------

    public function testInstanceRegistersExistingObject(): void
    {
        $obj = new SimpleClass();
        $this->container->instance(SimpleInterface::class, $obj);

        $this->assertSame($obj, $this->container->get(SimpleInterface::class));
    }

    public function testInstanceRegistersScalarValue(): void
    {
        $this->container->instance('app.name', 'Sloop');

        $this->assertSame('Sloop', $this->container->get('app.name'));
    }

    public function testInstanceOverridesBinding(): void
    {
        $this->container->bind(SimpleInterface::class, SimpleClass::class);

        $obj = new SimpleClass();
        $this->container->instance(SimpleInterface::class, $obj);

        $this->assertSame($obj, $this->container->get(SimpleInterface::class));
    }

    public function testBindOverridesInstance(): void
    {
        $obj = new SimpleClass();
        $this->container->instance(SimpleInterface::class, $obj);
        $this->container->bind(SimpleInterface::class, SimpleClass::class);

        $result = $this->container->get(SimpleInterface::class);

        $this->assertNotSame($obj, $result);
        $this->assertInstanceOf(SimpleClass::class, $result);
    }

    public function testBindOverridesSingletonCache(): void
    {
        $this->container->singleton(SimpleInterface::class, SimpleClass::class);
        $cached = $this->container->get(SimpleInterface::class);

        $this->container->bind(SimpleInterface::class, SimpleClass::class);
        $result = $this->container->get(SimpleInterface::class);

        $this->assertNotSame($cached, $result);
    }

    public function testInstanceOverridesSingletonCache(): void
    {
        $this->container->singleton(SimpleInterface::class, SimpleClass::class);
        $this->container->get(SimpleInterface::class);

        $replacement = new SimpleClass();
        $this->container->instance(SimpleInterface::class, $replacement);

        $this->assertSame($replacement, $this->container->get(SimpleInterface::class));
    }

    public function testInstanceAcceptsNull(): void
    {
        $this->container->instance('nullable', null);

        $this->assertTrue($this->container->has('nullable'));
        $this->assertNull($this->container->get('nullable'));
    }

    // ---------------------------------------------------------------
    // has
    // ---------------------------------------------------------------

    public function testHasReturnsTrueForBinding(): void
    {
        $this->container->bind(SimpleInterface::class, SimpleClass::class);

        $this->assertTrue($this->container->has(SimpleInterface::class));
    }

    public function testHasReturnsTrueForInstance(): void
    {
        $this->container->instance('key', 'value');

        $this->assertTrue($this->container->has('key'));
    }

    public function testHasReturnsTrueForInstantiableClass(): void
    {
        $this->assertTrue($this->container->has(SimpleClass::class));
    }

    public function testHasReturnsFalseForAbstractClass(): void
    {
        $this->assertFalse($this->container->has(AbstractService::class));
    }

    public function testHasReturnsFalseForInterface(): void
    {
        $this->assertFalse($this->container->has(SimpleInterface::class));
    }

    public function testHasReturnsFalseForNonexistentClass(): void
    {
        $this->assertFalse($this->container->has('Nonexistent\\Class'));
    }

    // ---------------------------------------------------------------
    // Auto-wiring
    // ---------------------------------------------------------------

    public function testAutowireClassWithNoDependencies(): void
    {
        $result = $this->container->get(SimpleClass::class);

        $this->assertInstanceOf(SimpleClass::class, $result);
    }

    public function testAutowireClassWithDependency(): void
    {
        $result = $this->container->get(ClassWithDependency::class);

        $this->assertInstanceOf(ClassWithDependency::class, $result);
        $this->assertInstanceOf(SimpleClass::class, $result->dependency);
    }

    public function testAutowireClassWithInterfaceDependency(): void
    {
        $this->container->bind(SimpleInterface::class, SimpleClass::class);

        $result = $this->container->get(ClassWithInterfaceDependency::class);

        $this->assertInstanceOf(ClassWithInterfaceDependency::class, $result);
        $this->assertInstanceOf(SimpleClass::class, $result->dependency);
    }

    public function testAutowireClassWithDefaultParameter(): void
    {
        $result = $this->container->get(ClassWithDefault::class);

        $this->assertInstanceOf(ClassWithDefault::class, $result);
        $this->assertSame('default', $result->value);
    }

    public function testAutowireClassWithMultipleDependencies(): void
    {
        $result = $this->container->get(ClassWithMultipleDependencies::class);

        $this->assertInstanceOf(ClassWithMultipleDependencies::class, $result);
        $this->assertInstanceOf(SimpleClass::class, $result->simple);
        $this->assertInstanceOf(ClassWithNoConstructor::class, $result->noCtor);
        $this->assertSame('fallback', $result->name);
    }

    public function testAutowireResolvesNestedDependencies(): void
    {
        $result = $this->container->get(ClassWithNestedDependency::class);

        $this->assertInstanceOf(ClassWithNestedDependency::class, $result);
        $this->assertInstanceOf(ClassWithDependency::class, $result->nested);
        $this->assertInstanceOf(SimpleClass::class, $result->nested->dependency);
    }

    public function testAutowireReturnsNewInstanceEachTime(): void
    {
        $a = $this->container->get(SimpleClass::class);
        $b = $this->container->get(SimpleClass::class);

        $this->assertNotSame($a, $b);
    }

    public function testAutowireNullableResolvesWhenAvailable(): void
    {
        $result = $this->container->get(ClassWithNullableDependency::class);

        $this->assertInstanceOf(ClassWithNullableDependency::class, $result);
        $this->assertInstanceOf(SimpleClass::class, $result->dependency);
    }

    public function testAutowireNullableFallsToDefaultWhenUnresolvable(): void
    {
        $result = $this->container->get(ClassWithNullableInterfaceDependency::class);

        $this->assertInstanceOf(ClassWithNullableInterfaceDependency::class, $result);
        $this->assertNull($result->dependency);
    }

    public function testAutowireUnionTypeWithoutDefaultThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve parameter');

        $this->container->get(ClassWithUnionType::class);
    }

    public function testAutowireThrowsForUnboundInterfaceDependency(): void
    {
        $this->expectException(EntryNotFoundException::class);
        $this->expectExceptionMessage('Entry not found');

        $this->container->get(ClassWithInterfaceDependency::class);
    }

    public function testAutowireClassWithNoConstructor(): void
    {
        $result = $this->container->get(ClassWithNoConstructor::class);

        $this->assertInstanceOf(ClassWithNoConstructor::class, $result);
    }

    // ---------------------------------------------------------------
    // Error cases
    // ---------------------------------------------------------------

    public function testGetThrowsForNonexistentEntry(): void
    {
        $this->expectException(EntryNotFoundException::class);
        $this->expectExceptionMessage('Entry not found');

        $this->container->get('Nonexistent\\Class');
    }

    public function testGetThrowsForAbstractClass(): void
    {
        $this->expectException(EntryNotFoundException::class);
        $this->expectExceptionMessage('Entry is not instantiable');

        $this->container->get(AbstractService::class);
    }

    public function testGetThrowsForCircularDependency(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $this->container->get(CircularA::class);
    }

    public function testGetThrowsForUnresolvableParameter(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve parameter');

        $this->container->get(ClassWithUnresolvableParam::class);
    }

    public function testEntryNotFoundExceptionImplementsPsr(): void
    {
        $exception = new EntryNotFoundException('test');

        $this->assertInstanceOf(NotFoundExceptionInterface::class, $exception);
    }

    public function testContainerExceptionImplementsPsr(): void
    {
        $exception = new ContainerException('test');

        $this->assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }
}

// ---------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------

interface SimpleInterface
{
}

abstract class AbstractService
{
}

class SimpleClass implements SimpleInterface
{
}

class ClassWithNoConstructor
{
}

readonly class ClassWithDependency
{
    public function __construct(
        public SimpleClass $dependency,
    ) {
    }
}

readonly class ClassWithMultipleDependencies
{
    public function __construct(
        public SimpleClass $simple,
        public ClassWithNoConstructor $noCtor,
        public string $name = 'fallback',
    ) {
    }
}

readonly class ClassWithInterfaceDependency
{
    public function __construct(
        public SimpleInterface $dependency,
    ) {
    }
}

readonly class ClassWithDefault
{
    public function __construct(
        public string $value = 'default',
    ) {
    }
}

readonly class ClassWithNullableDependency
{
    public function __construct(
        public ?SimpleClass $dependency = null,
    ) {
    }
}

readonly class ClassWithNullableInterfaceDependency
{
    public function __construct(
        public ?SimpleInterface $dependency = null,
    ) {
    }
}

readonly class ClassWithUnionType
{
    public function __construct(
        public SimpleClass|string $value,
    ) {
    }
}

readonly class ClassWithNestedDependency
{
    public function __construct(
        public ClassWithDependency $nested,
    ) {
    }
}

readonly class ClassWithUnresolvableParam
{
    public function __construct(
        public string $required,
    ) {
    }
}

readonly class CircularA
{
    public function __construct(
        public CircularB $b,
    ) {
    }
}

readonly class CircularB
{
    public function __construct(
        public CircularA $a,
    ) {
    }
}
