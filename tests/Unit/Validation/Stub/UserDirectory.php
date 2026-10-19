<?php

declare(strict_types=1);

namespace Sloop\Tests\Unit\Validation\Stub;

final class UserDirectory
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param list<string> $names
     */
    public function __construct(
        private readonly array $names,
    ) {
    }

    public function exists(string $name): bool
    {
        $this->asked[] = $name;

        return \in_array($name, $this->names, true);
    }

    public static function isNotReserved(string $name): bool
    {
        return $name !== 'admin';
    }
}
