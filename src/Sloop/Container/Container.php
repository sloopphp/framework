<?php

declare(strict_types=1);

namespace Sloop\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Dependency injection container with auto-wiring support.
 *
 * Implements PSR-11 ContainerInterface. Supports explicit bindings,
 * singletons, instance registration, and constructor auto-wiring.
 */
final class Container implements ContainerInterface
{
    /**
     * Registered bindings (class name or closure).
     *
     * @var array<string, array{resolver: Closure|string, shared: bool}>
     */
    private array $bindings = [];

    /**
     * Resolved singleton instances.
     *
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Class names currently being resolved, for circular dependency detection.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Register a binding that creates a new instance on every resolution.
     *
     * @param string         $id       Identifier (typically an interface or class name)
     * @param Closure|string $resolver Closure or concrete class name
     * @return void
     */
    public function bind(string $id, Closure|string $resolver): void
    {
        $this->bindings[$id] = ['resolver' => $resolver, 'shared' => false];
        unset($this->instances[$id]);
    }

    /**
     * Register a binding that creates an instance only once and caches it.
     *
     * @param string         $id       Identifier (typically an interface or class name)
     * @param Closure|string $resolver Closure or concrete class name
     * @return void
     */
    public function singleton(string $id, Closure|string $resolver): void
    {
        $this->bindings[$id] = ['resolver' => $resolver, 'shared' => true];
        unset($this->instances[$id]);
    }

    /**
     * Register an existing instance in the container.
     *
     * @param string $id       Identifier (typically an interface or class name)
     * @param mixed  $instance The instance to register
     * @return void
     */
    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
        unset($this->bindings[$id]);
    }

    /**
     * Resolve an entry from the container.
     *
     * Checks in order: registered instances, explicit bindings, then
     * falls back to auto-wiring via constructor reflection.
     *
     * @param string $id Identifier of the entry to look for
     * @return mixed
     * @throws EntryNotFoundException If the entry cannot be found or auto-wired
     * @throws ContainerException     If a circular dependency is detected
     */
    public function get(string $id): mixed
    {
        if (\array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            return $this->resolveBinding($id);
        }

        return $this->autowire($id);
    }

    /**
     * Check if the container can resolve the given identifier.
     *
     * Returns true if the identifier has a registered instance, binding,
     * or is an instantiable class. Note that a true return does not guarantee
     * get() will succeed — constructor dependency resolution may still throw
     * ContainerException at resolve time (per PSR-11 §1.3.1).
     *
     * @param string $id Identifier of the entry to look for
     * @return bool
     */
    public function has(string $id): bool
    {
        if (\array_key_exists($id, $this->instances)) {
            return true;
        }

        if (isset($this->bindings[$id])) {
            return true;
        }

        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    /**
     * Resolve an entry from an explicit binding.
     *
     * @param string $id Identifier of the binding
     * @return mixed
     * @throws EntryNotFoundException If the concrete class cannot be found or is not instantiable
     * @throws ContainerException     If resolution fails
     */
    private function resolveBinding(string $id): mixed
    {
        $binding  = $this->bindings[$id];
        $resolver = $binding['resolver'];

        if ($binding['shared'] && \array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $instance = $resolver instanceof Closure
            ? $resolver($this)
            : $this->autowire($resolver);

        if ($binding['shared']) {
            $this->instances[$id] = $instance;
        }

        return $instance;
    }

    /**
     * Auto-wire a class by resolving its constructor dependencies.
     *
     * @param string $className Fully qualified class name
     * @return object
     * @throws EntryNotFoundException If the class does not exist or is not instantiable
     * @throws ContainerException     If a circular dependency is detected or instantiation fails
     */
    private function autowire(string $className): object
    {
        if (!class_exists($className)) {
            throw new EntryNotFoundException(
                'Entry not found: ' . $className
            );
        }

        $reflection = new ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw new EntryNotFoundException(
                'Entry is not instantiable: ' . $className
            );
        }

        if (isset($this->resolving[$className])) {
            throw new ContainerException(
                'Circular dependency detected while resolving: ' . $className
            );
        }

        $this->resolving[$className] = true;

        try {
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return $reflection->newInstance();
            }

            $parameters = $constructor->getParameters();
            $args       = $this->resolveParameters($parameters, $className);

            return $reflection->newInstanceArgs($args);
        } catch (\ReflectionException $e) {
            throw new ContainerException(
                'Failed to instantiate ' . $className . ': ' . $e->getMessage(),
                0,
                $e,
            );
        } finally {
            unset($this->resolving[$className]);
        }
    }

    /**
     * Resolve an array of constructor parameters.
     *
     * @param array<int, ReflectionParameter> $parameters Constructor parameters
     * @param string                          $className  Class being resolved (for error messages)
     * @return array<int, mixed>
     * @throws EntryNotFoundException If a typed parameter cannot be resolved
     * @throws ContainerException     If a parameter cannot be resolved
     */
    private function resolveParameters(array $parameters, string $className): array
    {
        $args = [];

        foreach ($parameters as $param) {
            $args[] = $this->resolveParameter($param, $className);
        }

        return $args;
    }

    /**
     * Resolve a single constructor parameter.
     *
     * @param ReflectionParameter $param     Parameter to resolve
     * @param string              $className Class being resolved (for error messages)
     * @return mixed
     * @throws EntryNotFoundException If a typed parameter cannot be resolved
     * @throws ContainerException     If the parameter cannot be resolved
     */
    private function resolveParameter(ReflectionParameter $param, string $className): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            try {
                return $this->get($type->getName());
            } catch (EntryNotFoundException $e) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }

                throw $e;
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new ContainerException(
            'Cannot resolve parameter $' . $param->getName()
            . ' in ' . $className . '::__construct().'
        );
    }
}
