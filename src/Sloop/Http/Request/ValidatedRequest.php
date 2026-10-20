<?php

declare(strict_types=1);

namespace Sloop\Http\Request;

/**
 * Validated input of a request, handed to a controller action as typed properties.
 *
 * A subclass declares one `public private(set)` property for each field and a
 * `rules()` method returning the rules keyed by the same names:
 *
 * ```php
 * final class CreateUserRequest extends ValidatedRequest
 * {
 *     public private(set) string $name;
 *     public private(set) ?int $age;
 *
 *     protected function rules(): array
 *     {
 *         return [
 *             'name' => Rule::string()->required()->maxLength(50),
 *             'age'  => Rule::int()->between(0, 150),
 *         ];
 *     }
 * }
 * ```
 *
 * An action that declares a parameter of the subclass receives it validated.
 * When validation fails, the action is not called and the client gets a 422
 * with the errors. The input is the body (form values and files, or the JSON
 * body) for POST, PUT and PATCH; for GET, HEAD and DELETE it is the query
 * string together with the body, and a field sent in both is answered with
 * a 400 (as is a field sent as both a form value and a file). The
 * parameters of rules() are resolved the way the action's are: objects
 * from the container, builtin types from the route parameters.
 *
 * The properties are `private(set)` rather than `readonly` because the
 * framework fills them after construction, which static analysers report for
 * a readonly property; outside the class they cannot be written either way.
 *
 * Each property's type must be the type of its field's validated value, and
 * must accept null when the field is neither required() nor given a
 * default(). A property without a rule, a rule without a property, or a
 * property that is not `public private(set)` is a mistake in the class,
 * reported with a LogicException when the class is first resolved.
 */
abstract class ValidatedRequest
{
    /**
     * Create an empty request; the framework fills the properties once the input is valid.
     *
     * @return void
     */
    final public function __construct()
    {
    }
}
