<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null> */
final class WorkflowGraph implements CastsAttributes
{
    public const PREFIX = 'encrypted:';

    public const SECRET_RULES = ['url', 'body'];

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $graph = is_string($value) ? json_decode($value, true) : null;

        return is_array($graph) ? self::map($graph, fn (string $v) => str_starts_with($v, self::PREFIX) ? Crypt::decryptString(substr($v, strlen(self::PREFIX))) : $v) : null;
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        return (string) json_encode(self::map($value, fn (string $v) => $v === '' ? $v : self::PREFIX.Crypt::encryptString($v)));
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  callable(string): string  $change
     * @return array<string, mixed>
     */
    public static function map(array $graph, callable $change): array
    {
        foreach ($graph['nodes'] ?? [] as $i => $node) {
            $node = (array) $node;
            $rules = $node['rules'] ?? null;
            if (($node['kind'] ?? null) !== 'http' || ! (is_array($rules) || is_object($rules))) {
                continue;
            }
            $rules = (array) $rules;
            foreach (self::SECRET_RULES as $rule) {
                if (is_string($rules[$rule] ?? null)) {
                    $rules[$rule] = $change($rules[$rule]);
                }
            }
            $graph['nodes'][$i] = [...$node, 'rules' => $rules];
        }

        return $graph;
    }
}
