<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Models\ServiceGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class ServiceGroups
{
    /** @return Collection<int, ServiceGroup> */
    public static function of(Machine $machine): Collection
    {
        return $machine->serviceGroups()->orderBy('name')->get();
    }

    public static function find(Machine $machine, mixed $reference): ServiceGroup
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new ToolError('Say which group: its id or name. list_service_groups shows them.');
        }

        $groups = self::of($machine);
        if (ctype_digit($reference) && ($group = $groups->firstWhere('id', (int) $reference)) !== null) {
            return $group;
        }

        $matches = $groups->filter(fn (ServiceGroup $g) => mb_strtolower($g->name) === mb_strtolower($reference));
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several groups on {$machine->name} are called “{$reference}”: ".$matches->map(fn (ServiceGroup $g) => "#{$g->id}")->implode(', ').'. Use the id.');
        }

        throw new ToolError("No service group “{$reference}” on {$machine->name}.".($groups->isEmpty() ? ' It has none yet.' : ' It has: '.$groups->pluck('name')->implode(', ').'.'));
    }

    /** @param  array<string, mixed>  $service */
    public static function holds(ServiceGroup $group, array $service): bool
    {
        $members = $group->services ?? [];

        return in_array($service['key'], $members, true) || in_array(ServiceWork::memberKey($service), $members, true);
    }

    /**
     * @param  Collection<int, ServiceGroup>  $groups
     * @param  array<string, mixed>  $service
     */
    public static function containing(Collection $groups, array $service): ?ServiceGroup
    {
        return $groups->first(fn (ServiceGroup $group) => self::holds($group, $service));
    }

    /**
     * @param  list<mixed>  $references
     * @return list<string>
     */
    public static function members(McpContext $context, Machine $machine, array $references): array
    {
        return array_values(array_unique(array_map(
            fn ($reference) => ServiceWork::memberKey($context->service($machine, $reference)),
            $references,
        )));
    }

    /** @return array<string, mixed> */
    public static function present(McpContext $context, Machine $machine, ServiceGroup $group): array
    {
        $services = $context->services($machine)->map(fn (array $s) => $context->describeService($machine, $s));
        $inside = $services->filter(fn (array $s) => self::holds($group, $s))->values();
        $found = $inside->flatMap(fn (array $s) => [$s['key'], ServiceWork::memberKey($s)])->all();

        return [
            'id' => $group->id,
            'name' => $group->name,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'icon' => $group->icon_path === null ? null : Storage::disk('public')->url($group->icon_path),
            'services' => $inside->map(fn (array $s) => [
                'key' => $s['key'],
                'name' => $s['display'],
                'status' => $s['status'] ?? 'unknown',
            ])->all(),
            'missing' => array_values(array_diff($group->services ?? [], $found)),
            'url' => $context->url("/machines/{$machine->id}"),
        ];
    }

    /** @return array<string, mixed> */
    public static function rules(bool $creating): array
    {
        return [
            'machine' => ['required', ServiceWork::reference()],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:60', 'regex:/\S/'],
            'services' => ['sometimes', 'array', 'list', 'max:500'],
            'services.*' => ['string', 'max:300'],
        ];
    }

    /**
     * @param  list<string>  $members
     * @return list<array{id: int, name: string, services: list<string>}>
     */
    public static function losing(Machine $machine, ?ServiceGroup $group, array $members): array
    {
        return array_values(self::of($machine)
            ->reject(fn (ServiceGroup $other) => $other->id === $group?->id)
            ->map(fn (ServiceGroup $other) => ['id' => $other->id, 'name' => $other->name, 'services' => array_values(array_intersect($other->services ?? [], $members))])
            ->filter(fn (array $other) => $other['services'] !== [])
            ->all());
    }
}
