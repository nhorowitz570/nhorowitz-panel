<?php

namespace App\Mcp\Tools\Workspace;

use Closure;

final class Rules
{
    public static function reference(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail("The {$attribute} must be an id or a name.");
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function given(array $arguments): array
    {
        return array_filter($arguments, fn (mixed $value) => $value !== null && ! (is_string($value) && trim($value) === ''));
    }
}
