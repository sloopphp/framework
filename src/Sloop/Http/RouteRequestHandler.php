<?php

declare(strict_types=1);

namespace Sloop\Http;

use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use Sloop\Container\Container;
use Sloop\Container\ContainerException;
use Sloop\Container\EntryNotFoundException;
use Sloop\Http\Request\Request;
use Sloop\Http\Request\ValidatedInput;
use Sloop\Http\Request\ValidatedRequest;
use Sloop\Http\Request\ValidatedRequestShape;
use Sloop\Http\Response\ResponseFormatterInterface;
use Sloop\Routing\Route;
use Sloop\Validation\BoolParser;
use Sloop\Validation\NumberParser;
use Sloop\Validation\ValidationResult;
use Sloop\Validation\Validator;
use UnexpectedValueException;

/**
 * Final request handler that executes the controller for a resolved route.
 *
 * Used as the innermost handler in the route-middleware dispatcher stack.
 * Resolves the controller from the container, invokes the action with
 * arguments resolved from the Sloop Request, route parameters, and the
 * container (method-level DI), and wraps non-response return values via
 * the configured response formatter.
 *
 * @internal
 */
final readonly class RouteRequestHandler implements RequestHandlerInterface
{
    /**
     * Create a new route request handler.
     *
     * @param  Container                  $container DI container for controller resolution
     * @param  Route                      $route     Resolved route
     * @param  array<string, string>      $params    Route parameters
     * @param  ResponseFormatterInterface $formatter Formatter for non-response return values
     * @return void
     */
    public function __construct(
        private Container $container,
        private Route $route,
        private array $params,
        private ResponseFormatterInterface $formatter,
    ) {
    }

    /**
     * Invoke the controller action and return the response.
     *
     * The Sloop Request wrapper is built here from the incoming PSR-7
     * request, so mutations made by route middleware (withAttribute,
     * withParsedBody, ...) are visible to the controller — matching the
     * behavior of global middleware.
     *
     * @param  ServerRequestInterface   $request PSR-7 request as passed through the route middleware chain
     * @return ResponseInterface
     * @throws RuntimeException         If validating a ValidatedRequest fails the way Validator::validate() describes
     * @throws LogicException           If the controller cannot be resolved as an object, the action method cannot be reflected, the entry registered as the clock is not one, a parameter of the action or of a ValidatedRequest's rules() that is not of a class type (for the action, an untyped one only when another of its parameters has a type) either matches a route parameter while having a union or intersection type or a builtin type other than int, float, bool, string and mixed, or matches none while declaring neither a default nor a type that allows null, a ValidatedRequest subclass the action takes is declared wrongly (see ValidatedRequest), or the parsed body is not an array
     * @throws UnexpectedValueException If a sanitizer closure or a closure given to using() of a ValidatedRequest rule returns the wrong type
     * @throws InvalidArgumentException If the Validator refuses the rules of a ValidatedRequest subclass, or Rule::date()'s format cannot read back a limit, a default or today's date (see Validator::validate())
     * @throws EntryNotFoundException   If the controller or a typed dependency cannot be resolved by the container
     * @throws ContainerException       If a circular dependency is detected during resolution
     * @throws ReflectionException      If the rules() of a ValidatedRequest subclass throws one, which propagates unwrapped
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $controller = $this->container->get($this->route->controller);
        if (!\is_object($controller)) {
            throw new LogicException('Controller must be an object: ' . $this->route->controller);
        }

        $sloopRequest = new Request($request, $this->params);
        $args         = $this->buildActionArguments($controller::class, $this->route->action, $sloopRequest);
        if ($args instanceof ResponseInterface) {
            return $args;
        }

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
     * by type: Request types receive the Sloop Request, ValidatedRequest
     * subclasses receive the validated input, other object types are
     * resolved from the container, and every other parameter takes the
     * route parameter of its name (see routeValue()). Parameters are
     * resolved in declaration order, so a ValidatedRequest declared before a
     * route parameter answers with its 400 or 422 before that value is read.
     *
     * @param  string                              $className    Controller class name
     * @param  string                              $method       Action method name
     * @param  Request                             $sloopRequest Sloop request wrapping the current PSR-7 request
     * @return array<int, mixed>|ResponseInterface Positional arguments for the action, the 404 response when an int, float or bool route parameter cannot be read as that type, or the 400 / 422 response when a ValidatedRequest's input is refused
     * @throws RuntimeException                    If validating a ValidatedRequest fails the way Validator::validate() describes
     * @throws LogicException                      If the action method cannot be reflected, the entry registered as the clock is not one, a parameter of the action or of a ValidatedRequest's rules() that is not of a class type (for the action, an untyped one only when another of its parameters has a type) either matches a route parameter while having a union or intersection type or a builtin type other than int, float, bool, string and mixed, or matches none while declaring neither a default nor a type that allows null, a ValidatedRequest subclass is declared wrongly (see ValidatedRequest), or the parsed body is not an array
     * @throws UnexpectedValueException            If a sanitizer closure or a closure given to using() of a ValidatedRequest rule returns the wrong type
     * @throws InvalidArgumentException            If the Validator refuses the rules of a ValidatedRequest subclass, or Rule::date()'s format cannot read back a limit, a default or today's date (see Validator::validate())
     * @throws EntryNotFoundException              If a typed object dependency cannot be resolved by the container
     * @throws ContainerException                  If a circular dependency is detected during dependency resolution
     * @throws ReflectionException                 If the rules() of a ValidatedRequest subclass throws one, which propagates unwrapped
     */
    private function buildActionArguments(string $className, string $method, Request $sloopRequest): array|ResponseInterface
    {
        $parameters = self::reflectParameters($className, $method);

        if (self::isLegacyParameterList($parameters)) {
            return [$sloopRequest, ...array_values($this->params)];
        }

        $args = [];
        foreach ($parameters as $parameter) {
            $class = self::validatedRequestClass($parameter);
            if ($class === null) {
                $value = $this->routeValue($parameter);
                if ($value instanceof ResponseInterface) {
                    return $value;
                }
                $args[] = $value ?? $this->resolveParameter($parameter, $sloopRequest);
                continue;
            }

            $validated = $this->validatedRequest($class, $sloopRequest);
            if ($validated instanceof ResponseInterface) {
                return $validated;
            }
            $args[] = $validated;
        }

        return $args;
    }

    /**
     * The ValidatedRequest subclass a parameter declares, if it declares one.
     *
     * @param  ReflectionParameter                 $parameter Parameter to inspect
     * @return class-string<ValidatedRequest>|null
     */
    private static function validatedRequestClass(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        $name = $type->getName();

        return is_a($name, ValidatedRequest::class, true) ? $name : null;
    }

    /**
     * Validate the input of the request into a ValidatedRequest subclass.
     *
     * rules() receives its arguments the way an action does, except the
     * Request and other ValidatedRequests: a validated request never reads
     * the raw request.
     *
     * @param  class-string<ValidatedRequest>     $class        Subclass to fill
     * @param  Request                            $sloopRequest Sloop request wrapping the current PSR-7 request
     * @return ValidatedRequest|ResponseInterface The filled request, a 404 when an int, float or bool route parameter rules() asks for cannot be read as that type, a 400 when a field was sent twice, or a 422 with the errors
     * @throws LogicException                     If the entry registered as the clock is not one, the subclass is declared wrongly (see ValidatedRequest), rules() asks for the Request or a ValidatedRequest, a parameter of rules() that is not of a class type either matches a route parameter while having a union or intersection type or a builtin type other than int, float, bool, string and mixed, or matches none while declaring neither a default nor a type that allows null, or the parsed body is not an array
     * @throws UnexpectedValueException           If a sanitizer closure or a closure given to using() of a ValidatedRequest rule returns the wrong type
     * @throws RuntimeException                   If validation fails the way Validator::validate() describes (PCRE, a file's stream, ICU, a message pattern)
     * @throws EntryNotFoundException             If an object rules() asks for cannot be resolved by the container
     * @throws ContainerException                 If a circular dependency is detected during resolution
     * @throws ReflectionException                If its rules() throws one, which propagates unwrapped (reflecting the subclass does not fail: validatedRequestClass() passes only classes that exist)
     * @throws InvalidArgumentException           If the Validator refuses the rules, or Rule::date()'s format cannot read back a limit, a default or today's date (see Validator::validate())
     */
    private function validatedRequest(string $class, Request $sloopRequest): ValidatedRequest|ResponseInterface
    {
        $shape   = ValidatedRequestShape::of($class);
        $request = new $class();

        $arguments = [];
        foreach ($shape->rulesParameters() as $parameter) {
            // Checked before the route and the default are looked at: a
            // union that names the Request is refused even when it allows null.
            foreach (self::namedTypes($parameter->getType()) as $named) {
                $name = $named->getName();
                if ($name === Request::class || is_a($name, ValidatedRequest::class, true)) {
                    throw new LogicException(
                        $class . '::rules() cannot receive ' . $name . ': a validated request reads only its validated input.',
                    );
                }
            }
            $value = $this->routeValue($parameter);
            if ($value instanceof ResponseInterface) {
                return $value;
            }
            $arguments[] = $value ?? $this->resolveParameter($parameter, $sloopRequest);
        }
        $rules = $shape->rules($request, $arguments);
        // The rules are checked before any input is read, so a misdeclared rule surfaces whatever the client sent.
        $validator = new Validator($rules, $this->clock());

        $input = ValidatedInput::collect($sloopRequest->psrRequest(), $rules);
        if (\is_string($input)) {
            return $this->formatter->error($input, HttpStatus::BadRequest);
        }

        $result = $validator->validate($input);
        if ($result->failed()) {
            return $this->formatter->error('Validation failed.', HttpStatus::UnprocessableEntity, self::errorBody($result));
        }

        $shape->fill($request, $result->values());

        return $request;
    }

    /**
     * The named types a declared type is made of, with unions and intersections flattened.
     *
     * @param  ReflectionType|null       $type Declared type, or null when the parameter has none
     * @return list<ReflectionNamedType>
     */
    private static function namedTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }
        if (!$type instanceof ReflectionUnionType && !$type instanceof ReflectionIntersectionType) {
            return [];
        }

        $named = [];
        foreach ($type->getTypes() as $member) {
            $named = [...$named, ...self::namedTypes($member)];
        }

        return $named;
    }

    /**
     * The errors of a failed validation as the 422 response carries them.
     *
     * @param  ValidationResult                                                          $result Failed result
     * @return array<string, list<array{rule: string, params: object, message: string}>> The params as an object, so that they encode as a JSON object even when empty
     */
    private static function errorBody(ValidationResult $result): array
    {
        $body = [];
        foreach ($result->errors() as $key => $errors) {
            foreach ($errors as $error) {
                $body[$key][] = ['rule' => $error->rule, 'params' => (object) $error->params, 'message' => $error->message];
            }
        }

        return $body;
    }

    /**
     * The clock registered in the container, or null for the Validator's own system clock.
     *
     * @return ClockInterface|null
     * @throws LogicException         If the entry registered as the clock is not a ClockInterface
     * @throws EntryNotFoundException If the registered clock cannot be resolved
     * @throws ContainerException     If a circular dependency is detected during resolution
     */
    private function clock(): ?ClockInterface
    {
        if (!$this->container->has(ClockInterface::class)) {
            return null;
        }
        $clock = $this->container->get(ClockInterface::class);
        if (!$clock instanceof ClockInterface) {
            throw new LogicException('The entry registered as ' . ClockInterface::class . ' is not a clock.');
        }

        return $clock;
    }

    /**
     * The value of the route parameter a parameter reads, if it reads one.
     *
     * A class-typed parameter never reads the route. Any other parameter
     * (untyped, or of a builtin, union or intersection type) reads the route
     * parameter of its name when there is one.
     *
     * @param  ReflectionParameter                          $parameter Parameter to resolve
     * @return int|float|bool|string|ResponseInterface|null The value read into the parameter's type, a 404 when an int, float or bool route parameter cannot be read as that type, or null when the parameter does not read the route
     * @throws LogicException                               If the parameter matches a route parameter while having a union or intersection type, or a builtin type other than int, float, bool, string and mixed
     */
    private function routeValue(ReflectionParameter $parameter): int|float|bool|string|ResponseInterface|null
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return null;
        }

        $name = $parameter->getName();
        if (!\array_key_exists($name, $this->params)) {
            return null;
        }

        return $this->readRouteParameter($parameter, $this->params[$name]);
    }

    /**
     * Resolve a single action parameter that does not read the route.
     *
     * A class-typed parameter receives the Sloop Request or an entry of the
     * container. Any other parameter falls back to its default, then to null
     * when its type allows null.
     *
     * @param  ReflectionParameter    $parameter    Parameter to resolve
     * @param  Request                $sloopRequest Sloop request wrapping the current PSR-7 request
     * @return mixed                  Resolved argument value
     * @throws LogicException         If the parameter is not of a class type and declares neither a default nor a type that allows null
     * @throws EntryNotFoundException If a typed object parameter cannot be resolved by the container
     * @throws ContainerException     If a circular dependency is detected during resolution
     */
    private function resolveParameter(ReflectionParameter $parameter, Request $sloopRequest): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            return $typeName === Request::class ? $sloopRequest : $this->container->get($typeName);
        }

        $name = $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // An untyped parameter is required in PHP, although
        // ReflectionParameter::allowsNull() reports true for it; only a
        // declared type that allows null is filled with null.
        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        if ($type !== null && !$type instanceof ReflectionNamedType) {
            throw self::unsupportedType($name);
        }

        throw new LogicException('Route parameter not found: ' . $name);
    }

    /**
     * Read a route parameter into the type its parameter declares.
     *
     * An untyped, string or mixed parameter receives the value as given. An
     * int, float or bool parameter receives it read the way the Rule of that
     * type reads a field; a path segment that is not such a value names no
     * resource, so the request is answered with 404, the status of an
     * unmatched route.
     *
     * @param  ReflectionParameter                     $parameter Parameter the value is for
     * @param  string                                  $value     Route parameter value
     * @return int|float|bool|string|ResponseInterface The value read into the parameter's type, or a 404 when an int, float or bool route parameter cannot be read as that type
     * @throws LogicException                          If the parameter has a union or intersection type, or a builtin type other than int, float, bool, string and mixed
     */
    private function readRouteParameter(ReflectionParameter $parameter, string $value): int|float|bool|string|ResponseInterface
    {
        $type = $parameter->getType();
        $name = $parameter->getName();

        if ($type === null) {
            return $value;
        }

        if (!$type instanceof ReflectionNamedType) {
            throw self::unsupportedType($name);
        }

        $typeName = $type->getName();

        $parsed = match ($typeName) {
            'int'             => NumberParser::int($value),
            'float'           => NumberParser::float($value),
            'bool'            => BoolParser::bool($value),
            'string', 'mixed' => $value,
            default           => throw new LogicException(
                'Route parameter "' . $name . '" cannot be passed to ' . $type . ' $' . $name
                    . ': route values are read only into int, float, bool, string, mixed or untyped parameters.',
            ),
        };

        // The value is the client's and stays out of the body.
        return $parsed ?? $this->formatter->error(
            'The route parameter "' . $name . '" is not a valid ' . $typeName . '.',
            HttpStatus::NotFound,
        );
    }

    /**
     * The exception for a parameter whose union or intersection type the route parameters cannot fill.
     *
     * @param  string         $name Parameter name
     * @return LogicException
     */
    private static function unsupportedType(string $name): LogicException
    {
        return new LogicException('Unsupported parameter type for ' . $name . ': only named types are supported in method DI.');
    }

    /**
     * Check if every parameter in the list is untyped (legacy controller signature).
     *
     * @param  array<int, ReflectionParameter> $parameters Parameters to inspect
     * @return bool                            True if no parameter declares a type
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
     * @param  string                          $className Controller class name
     * @param  string                          $method    Action method name
     * @return array<int, ReflectionParameter> Cached parameter list
     * @throws LogicException                  If the method cannot be reflected (it does not exist on the class)
     */
    private static function reflectParameters(string $className, string $method): array
    {
        /** @var array<string, array<int, ReflectionParameter>> $cache */
        static $cache = [];

        $key = $className . '::' . $method;

        return $cache[$key]
            ??= self::reflectAction($className, $method)->getParameters();
    }

    /**
     * Reflect the action method a route names.
     *
     * Only this reflection is converted: a ReflectionException thrown by the
     * action's body or a route middleware's process() is the application's
     * own and propagates unchanged.
     *
     * @param  string           $className Controller class name
     * @param  string           $method    Action method name
     * @return ReflectionMethod
     * @throws LogicException   If the method does not exist on the class
     */
    private static function reflectAction(string $className, string $method): ReflectionMethod
    {
        try {
            return new ReflectionMethod($className, $method);
        } catch (ReflectionException $e) {
            throw new LogicException('Failed to reflect controller action: ' . $e->getMessage(), previous: $e);
        }
    }
}
