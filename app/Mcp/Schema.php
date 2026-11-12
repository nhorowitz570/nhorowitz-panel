<?php

namespace App\Mcp;

use stdClass;

final class Schema
{
    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    public static function object(array $properties = [], array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new stdClass : $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  list<string>|null  $enum
     * @return array<string, mixed>
     */
    public static function string(string $description, ?array $enum = null, ?int $maxLength = null): array
    {
        return array_filter([
            'type' => 'string',
            'description' => $description,
            'enum' => $enum,
            'maxLength' => $maxLength,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    public static function integer(string $description, ?int $minimum = null, ?int $maximum = null): array
    {
        return array_filter([
            'type' => 'integer',
            'description' => $description,
            'minimum' => $minimum,
            'maximum' => $maximum,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    public static function boolean(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function array(array $items, string $description, ?int $maxItems = null): array
    {
        return array_filter(['type' => 'array', 'items' => $items, 'description' => $description, 'maxItems' => $maxItems], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    public static function machine(string $description = 'The machine: its id or its name (as list_machines shows).'): array
    {
        return self::string($description, maxLength: 120);
    }

    /** @return array<string, mixed> */
    public static function service(string $description = 'The service: its key (like docker:4f2a… or nginx.service), its name, or the label it was given in ServerOS (as list_services shows).'): array
    {
        return self::string($description, maxLength: 300);
    }

    /** @return array<string, mixed> */
    public static function confirm(string $what): array
    {
        return self::string("Type {$what} exactly, to confirm. Ask the person first: this can’t be undone.", maxLength: 200);
    }
}
