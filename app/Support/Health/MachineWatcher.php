<?php

namespace App\Support\Health;

use App\Enums\MachineEnvironment;
use App\Models\Activity;
use App\Models\Machine;
use App\Support\Monitors\MonitorAlerts;

class MachineWatcher
{
    public const OFFLINE_ALERT_SECONDS = 300;

    public const DISK_ALERT = 90;

    public const DISK_CLEAR = 85;

    public const MEMORY_ALERT = WorkspaceHealth::MEMORY_WARN;

    public const MEMORY_ALERT_MINUTES = 10;

    public const MEMORY_CLEAR = 85;

    public function __construct(
        private readonly MonitorAlerts $alerts,
        private readonly ServiceWatcher $services,
    ) {}

    public function run(): int
    {
        $sent = 0;

        Machine::query()
            ->with('workspace')
            ->whereNotNull('uid')
            ->whereNotNull('last_seen_at')
            ->chunkById(200, function ($machines) use (&$sent): void {
                foreach ($machines as $machine) {
                    if ($machine->workspace->isActive()) {
                        $sent += $this->check($machine);
                    }
                }
            });

        return $sent;
    }

    public function check(Machine $machine): int
    {
        $state = $machine->alert_state ?? [];
        $before = $state;
        $sent = 0;
        $url = 'https://'.$machine->workspace->host().'/machines/'.$machine->id;
        $quiet = $machine->last_seen_at->lt(now()->subSeconds(self::OFFLINE_ALERT_SECONDS));
        $online = $machine->isOnline();

        $watchOffline = $machine->environment !== MachineEnvironment::Development;
        $maintenance = $machine->inMaintenance();

        if ($quiet && $watchOffline && ! isset($state['offline']) && $machine->last_seen_at->lt(now()->subDay())) {
            $state['offline'] = $machine->last_seen_at->getTimestamp();
        } elseif ($quiet && $watchOffline && ! isset($state['offline']) && ! $maintenance) {
            $state['offline'] = $machine->last_seen_at->getTimestamp();
            Activity::system($machine->workspace_id, 'machine.offline', $machine->name, $machine);
            $this->alerts->notify($machine->workspace, 'machine.offline', "{$machine->name} stopped reporting", [
                "Nothing from it since {$machine->last_seen_at->diffForHumans()}.",
                'It may be down or unreachable, or the ServerOS daemon stopped. Check it in your provider’s console; if it’s running, restart the daemon with “systemctl restart serverosd”.',
            ], $url, true, 'Open the machine', ['machine' => ['id' => $machine->id, 'name' => $machine->name]]);
            $sent++;
        } elseif ($online && isset($state['offline'])) {
            $seconds = now()->getTimestamp() - $state['offline'];
            unset($state['offline']);
            Activity::system($machine->workspace_id, 'machine.online', $machine->name, $machine, ['seconds' => $seconds]);
            $maintenance || $this->alerts->notify($machine->workspace, 'machine.online', "{$machine->name} is reporting again", [
                'Back after '.MonitorAlerts::duration($seconds).' without a report.',
            ], $url, false, 'Open the machine', ['machine' => ['id' => $machine->id, 'name' => $machine->name]]);
            $sent += $maintenance ? 0 : 1;
        }

        if ($online && $machine->disk_percent !== null) {
            if ($machine->disk_percent >= self::DISK_ALERT && ! isset($state['disk']) && ! $maintenance) {
                $state['disk'] = now()->getTimestamp();
                $disk = $machine->disks[0] ?? null;
                $free = $disk ? WorkspaceHealth::bytes($disk['total'] - $disk['used']) : null;
                Activity::system($machine->workspace_id, 'machine.disk_full', $machine->name, $machine, ['percent' => $machine->disk_percent, 'mount' => $disk['mount'] ?? null]);
                $this->alerts->notify($machine->workspace, 'machine.disk', "{$machine->name}’s disk is {$machine->disk_percent}% full", [
                    ($disk ? ($disk['mount'] === '/' ? 'The main disk' : "The disk at {$disk['mount']}")." has {$free} left." : 'It’s nearly out of space.'),
                    'When it fills, databases stop writing and apps usually crash. “See what’s using it” on the machine’s page shows what’s filling it and what’s safe to clear, or resize the disk.',
                ], $url, true, 'Open the machine', ['machine' => ['id' => $machine->id, 'name' => $machine->name, 'disk_percent' => $machine->disk_percent]]);
                $sent++;
            } elseif ($machine->disk_percent < self::DISK_CLEAR && isset($state['disk'])) {
                unset($state['disk']);
            }
        }

        $sent += $this->memory($machine, $state, $online && ! $maintenance, $url);

        if ($online) {
            $services = $state['services'] ?? [];
            $sent += $this->services->check($machine, $services, $maintenance);
            $state['services'] = $services;
        }

        if ($state !== $before) {
            $machine->forceFill(['alert_state' => $state === [] ? null : $state])->saveQuietly();
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function memory(Machine $machine, array &$state, bool $watch, string $url): int
    {
        $total = (int) ($machine->facts['memory_bytes'] ?? 0);
        if (! $watch || $total <= 0 || $machine->memory_used_mb === null) {
            unset($state['memory_high']);

            return 0;
        }
        $percent = (int) floor($machine->memory_used_mb * 1048576 / $total * 100);
        $extra = ['machine' => ['id' => $machine->id, 'name' => $machine->name, 'memory_percent' => $percent]];

        if ($percent >= self::MEMORY_ALERT) {
            $state['memory_high'] ??= now()->getTimestamp();
            if (isset($state['memory']) || now()->getTimestamp() - $state['memory_high'] < self::MEMORY_ALERT_MINUTES * 60) {
                return 0;
            }
            $state['memory'] = now()->getTimestamp();
            $this->alerts->notify($machine->workspace, 'machine.memory', "{$machine->name} is using {$percent}% of its memory", [
                'It’s been over '.self::MEMORY_ALERT.'% for '.MonitorAlerts::duration(now()->getTimestamp() - $state['memory_high']).'.',
                'When memory runs out, Linux stops the biggest process, often your database. See which service uses most on the machine page, then give it a limit or move to a bigger server.',
            ], $url, true, 'Open the machine', $extra);

            return 1;
        }

        unset($state['memory_high']);
        if ($percent < self::MEMORY_CLEAR) {
            if (isset($state['memory'])) {
                unset($state['memory']);
                $this->alerts->notify($machine->workspace, 'machine.memory_ok', "{$machine->name}’s memory is back to normal", [
                    "It’s using {$percent}% now.",
                ], $url, false, 'Open the machine', $extra);

                return 1;
            }
        }

        return 0;
    }
}
