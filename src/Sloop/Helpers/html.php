<?php

declare(strict_types=1);

use Sloop\Security\Html;

if (!function_exists('e')) {
    /**
     * Escape a string for HTML element text or a quoted attribute value.
     *
     * @param  string|null $value Value to escape; null yields an empty string
     * @return string
     */
    function e(?string $value): string
    {
        return Html::escape($value);
    }
}
