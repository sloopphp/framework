<?php

declare(strict_types=1);

namespace Sloop\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Sloop\Container\Container;
use Sloop\Container\ContainerException;
use Sloop\Container\EntryNotFoundException;
use Sloop\Http\Request\Request;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Routing\Route;

/**
 * Final request handler that executes the controller for a resolved route.
 *
 * Used as the innermost handler in the route-middleware dispatcher stack.
 * Resolves the controller from the container, invokes the action with
 * arguments resolved from the Sloop Request, route parameters, and the
 * container (method-level DI), and wraps non-response return values via
 * the configured response formatter.
 */
final readonly class RouteRequestHandler implements RequestHandlerInterface
{
    /**
     * Create a new route request handler.
     *
     * @param Container                  $container    DI container for controller resolution
     * @param Route                      $route        Resolved route
     * @param Request                    $sloopRequest Sloop request wrapping the PSR-7 request
     * @param array<string, string>      $params       Route parameters
     * @param ResponseFormatterInterface $formatter    Formatter for non-response return values
     * @return void
     */
    public function __construct(
        private Container $container,
        private Route $route,
        private Request $sloopRequest,
        private array $params,
        private ResponseFormatterInterface $formatter,
    ) {
    }

    /**
     * Invoke the controller action and return the response.
     *
     * @param  ServerRequestInterface $request PSR-7 request (unused; route parameters are already bound)
     * @return ResponseInterface
     * @throws RuntimeException        If the controller cannot be resolved as an object, a route parameter is missing, or a parameter type is unsupported
     * @throws EntryNotFoundException  If the controller or a typed dependency cannot be resolved by the container
     * @throws ContainerException      If a circular dependency is detected during resolution
     * @throws ReflectionException     If the controller action method cannot be reflected
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $controller = $this->container->get($this->route->controller);
        if (!\is_object($controller)) {
            throw new RuntimeException('Controller must be an object: ' . $this->route->controller);
        }

        $args = $this->buildActionArguments($controller::class, $this->route->action);

        $result = $controller->{$this->route->action}(...$args);

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        return $this->formatter->success($result);
    }

    /**
     * Build the argument list for the controller action.
     *
     * When every parameter is untyped, falls back to the legacy positional
     * pattern `[Request, ...routeParams]`. Otherwise resolves each parameter
     * by type: Request types receive the Sloop Request, builtin types come
     * from route parameters (looked up by name, cast to the declared type),
     * and other object types are resolved from the container.
     *
     * @param  string $className Controller class name
     * @param  string $method    Action method name
     * @return array<int, mixed> Positional arguments for the action
     * @throws RuntimeException       If a route parameter is missing or a parameter type is unsupported
     * @throws EntryNotFoundException If a typed object dependency cannot be resolved by the container
     * @throws ContainerException     If a circular dependency is detected during dependency resolution
     * @throws ReflectionException    If the action method cannot be reflected
     */
    private function buildActionArguments(string $className, string $method): array
    {
        $parameters = self::reflectParameters($className, $method);

        if (self::isLegacyParameterList($parameters)) {
            return [$this->sloopRequest, ...array_values($this->params)];
        }

        $args = [];
        foreach ($parameters as $parameter) {
            $args[] = $this->resolveParameter($parameter);
        }

        return $args;
    }

    /**
     * Resolve a single action parameter.
     *
     * @param  ReflectionParameter $parameter Parameter to resolve
     * @return mixed Resolved argument value
     * @throws RuntimeException       If the parameter is untyped, uses a union/intersection type, or matches no route parameter
     * @throws EntryNotFoundException If a typed object parameter cannot be resolved by the container
     * @throws ContainerException     If a circular dependency is detected during resolution
     */
    private function resolveParameter(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            throw new RuntimeException(
                'Unsupported parameter type for ' . $parameter->getName()
                    . ': only named types are supported in method DI.',
            );
        }

        $typeName = $type->getName();

        if ($typeName === Request::class) {
            return $this->sloopRequest;
        }

        if ($type->isBuiltin()) {
            return $this->resolveBuiltinParameter($parameter, $typeName);
        }

        return $this->container->get($typeName);
    }

    /**
     * Resolve a builtin-typed parameter from the route parameter map.
     *
     * @param  ReflectionParameter $parameter Parameter to resolve
     * @param  string              $typeName  Declared builtin type name
     * @return mixed Cast route parameter value, or the parameter's default, or null if nullable and absent
     * @throws RuntimeException If the route parameter is missing and no default/nullable is declared, or if the value cannot be cast to a numeric type
     */
    private function resolveBuiltinParameter(ReflectionParameter $parameter, string $typeName): mixed
    {
        $name = $parameter->getName();

        if (!\array_key_exists($name, $this->params)) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($parameter->getType()?->allowsNull() === true) {
                return null;
            }

            throw new RuntimeException('Route parameter not found: ' . $name);
        }

        $value = $this->params[$name];

        if (($typeName === 'int' || $typeName === 'float') && !is_numeric($value)) {
            throw new RuntimeException(
                'Route parameter "' . $name . '" must be ' . $typeName . ', got: ' . $value,
            );
        }

        return self::castBuiltin($value, $typeName);
    }

    /**
     * Cast a route parameter string to a declared builtin type.
     *
     * @param  string $value    Raw route parameter value
     * @param  string $typeName Declared builtin type name
     * @return int|float|bool|string Cast value
     */
    private static function castBuiltin(string $value, string $typeName): int|float|bool|string
    {
        return match ($typeName) {
            'int'    => (int) $value,
            'float'  => (float) $value,
            'bool'   => $value !== '' && $value !== '0' && strtolower($value) !== 'false',
            default  => $value,
        };
    }

    /**
     * Check if every parameter in the list is untyped (legacy controller signature).
     *
     * @param  array<int, ReflectionParameter> $parameters Parameters to inspect
     * @return bool True if no parameter declares a type
     *
     * @noinspection PhpArrayAllCanBeUsedInspection
     */
    private static function isLegacyParameterList(array $parameters): bool
    {
        foreach ($parameters as $parameter) {
            if ($parameter->getType() !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reflect an action method's parameters, caching the result.
     *
     * The cache uses a function-static map because readonly classes cannot
     * declare static properties.
     *
     * @param  string $className Controller class name
     * @param  string $method    Action method name
     * @return array<int, ReflectionParameter> Cached parameter list
     * @throws ReflectionException If the method does not exist on the class
     */
    private static function reflectParameters(string $className, string $method): array
    {
        /** @var array<string, array<int, ReflectionParameter>> $cache */
        static $cache = [];

        $key = $className . '::' . $method;

        return $cache[$key]
            ??= (new ReflectionMethod($className, $method))->getParameters();
    }
}
