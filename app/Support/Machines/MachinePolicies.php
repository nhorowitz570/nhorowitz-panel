<?php

namespace App\Support\Machines;

use App\Jobs\ApplyMachinePolicy;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePolicy;
use App\Models\Workspace;
use App\Support\DaemonGatewayClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MachinePolicies
{
    public static function defaultFor(Workspace $workspace): MachinePolicy
    {
        $policy = $workspace->machinePolicies()->where('is_default', true)->orderBy('id')->first();
        if ($policy !== null) {
            return $policy;
        }

        return DB::transaction(function () use ($workspace) {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();

            return $workspace->machinePolicies()->where('is_default', true)->first()
                ?? $workspace->machinePolicies()->create(['name' => __('Default'), 'is_default' => true, ...MachinePolicy::preset(null)]);
        });
    }

    public static function for(Machine $machine): MachinePolicy
    {
        return self::resolve($machine)['policy'];
    }

    /**
     * @param  Collection<int, MachinePolicy>|null  $ruled
     * @return array{policy: MachinePolicy, from: 'machine'|'group'|'rule'|'default', group: MachineGroup|null}
     */
    public static function resolve(Machine $machine, ?Collection $ruled = null, ?MachinePolicy $default = null): array
    {
        if ($machine->machine_policy_id !== null && ($own = $machine->policy) !== null) {
            return ['policy' => $own, 'from' => 'machine', 'group' => null];
        }

        $group = $machine->machine_group_id === null ? null : $machine->group;
        if ($group?->machine_policy_id !== null && ($groups = $group->policy) !== null) {
            return ['policy' => $groups, 'from' => 'group', 'group' => $group];
        }

        foreach ($ruled ?? self::ruled($machine->workspace_id) as $policy) {
            if ($policy->appliesTo($machine)) {
                return ['policy' => $policy, 'from' => 'rule', 'group' => null];
            }
        }

        return ['policy' => $default ?? self::defaultFor($machine->workspace), 'from' => 'default', 'group' => null];
    }

    /** @return Collection<int, MachinePolicy> */
    public static function ruled(int $workspaceId): Collection
    {
        return MachinePolicy::query()->where('workspace_id', $workspaceId)->whereNotNull('applies_to')->orderBy('id')->get()
            ->filter(fn (MachinePolicy $policy) => ($policy->applies_to ?? []) !== [])->values();
    }

    /** @return Builder<Machine> */
    public static function following(MachinePolicy $policy): Builder
    {
        $workspace = $policy->workspace;
        $ids = array_keys(array_filter(self::effective($workspace, null), fn (int $id) => $id === $policy->id));

        return Machine::query()->where('workspace_id', $policy->workspace_id)->whereIn('id', $ids);
    }

    /** @return array<string, bool|string> */
    public static function enrolSettings(Machine $machine): array
    {
        return self::for($machine)->toDaemon();
    }

    /** @param  iterable<Machine|int>  $machines */
    public static function push(iterable $machines): void
    {
        foreach ($machines as $machine) {
            ApplyMachinePolicy::dispatch($machine instanceof Machine ? $machine->id : (int) $machine)->afterCommit();
        }
    }

    /**
     * @template T
     *
     * @param  iterable<int>  $machineIds
     * @param  callable(): T  $change
     * @return T
     */
    public static function whileChanging(Workspace $workspace, iterable $machineIds, callable $change): mixed
    {
        $ids = array_values(collect($machineIds)->map(fn ($id) => (int) $id)->unique()->all());
        $before = self::effective($workspace, $ids);
        $result = $change();
        $after = self::effective($workspace, $ids);
        self::push(array_keys(array_diff_assoc($after, $before)));

        return $result;
    }

    /**
     * @param  list<int>|null  $ids
     * @return array<int, int>
     */
    public static function effective(Workspace $workspace, ?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }
        $ruled = self::ruled($workspace->id);
        $default = self::defaultFor($workspace);

        return $workspace->machines()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->with(['policy', 'group.policy'])->get()
            ->mapWithKeys(fn (Machine $machine) => [$machine->id => self::resolve($machine, $ruled, $default)['policy']->id])
            ->all();
    }

    /** @param  array<string, mixed>|null  $reported */
    public static function sync(Machine $machine, ?array $reported = null): bool
    {
        $policy = self::for($machine);
        $wanted = $policy->toDaemon();
        if ($reported !== null) {
            $wanted = array_filter($wanted, fn ($value, $key) => array_key_exists($key, $reported) && $reported[$key] !== $value, ARRAY_FILTER_USE_BOTH);
        }

        $machine->forceFill(['log_ip_masking' => $policy->log_ip_masking]);

        if ($wanted === []) {
            $machine->forceFill(['policy_pending' => false])->save();

            return true;
        }

        try {
            if ($machine->uid === null) {
                throw new RuntimeException('Not connected yet.');
            }
            app(DaemonGatewayClient::class)->control($machine, ['type' => 'reconfigure', ...$wanted]);
            $machine->forceFill(['policy_pending' => false])->save();

            return true;
        } catch (RuntimeException) {
            $machine->forceFill(['policy_pending' => true])->save();

            return false;
        }
    }
}
