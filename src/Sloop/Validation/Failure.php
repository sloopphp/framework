<?php

declare(strict_types=1);

namespace Sloop\Validation;

/**
 * A failed rule before its message is resolved.
 *
 * The field rule knows which rule failed and with what parameters, but not
 * the language file; the Validator turns each failure into a ValidationError
 * once it has chosen the message.
 *
 * A failure raised inside an array carries the way down to the element it
 * came from: $path holds the raw keys, which the Validator joins with dots to
 * key the error, and $labelPath holds the same steps as they are shown to a
 * person.
 *
 * @internal Produced by FieldRule, consumed by Validator.
 */
final readonly class Failure
{
    /**
     * Create a failure.
     *
     * @param string                                                 $rule       Name of the rule that failed
     * @param array<string, int|float|string|list<int|float|string>> $params     Rule parameters keyed by placeholder name
     * @param string|null                                            $message    Message given to that rule with `message:`, if any
     * @param list<string|int>                                       $path       Keys from the declared field down to the value that failed
     * @param list<string>                                           $labelPath  The same steps as they appear in a message
     * @param string|null                                            $otherLabel Label of the field a comparison named, which only the validator holding both sides can resolve
     */
    public function __construct(
        public string $rule,
        public array $params = [],
        public ?string $message = null,
        public array $path = [],
        public array $labelPath = [],
        public ?string $otherLabel = null,
    ) {
    }

    /**
     * The same failure, one step deeper: it happened under $key of the array being validated.
     *
     * The message of the element's own message() is taken here, since the
     * Validator only knows the rule of the declared field.
     *
     * @param  string|int  $key     Key the element sat under
     * @param  string      $label   How that step is shown in a message
     * @param  string|null $message Message of the element's message(), used when the rule has none of its own
     * @return self
     */
    public function under(string|int $key, string $label, ?string $message = null): self
    {
        return new self(
            $this->rule,
            $this->params,
            $this->message ?? $message,
            [$key, ...$this->path],
            [$label, ...$this->labelPath],
            $this->otherLabel,
        );
    }
}
