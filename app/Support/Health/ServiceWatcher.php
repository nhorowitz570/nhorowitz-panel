<?php

namespace App\Support\Health;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\Monitors\MonitorAlerts;
use RuntimeException;

class ServiceWatcher
{
    public const LOOP_RESTARTS = 3;

    public const LOOP_WINDOW_SECONDS = 900;

    public const LOOP_SETTLED_SECONDS = 1800;

    public const RESCAN_EVERY_SECONDS = 600;

    public function __construct(
        private readonly MonitorAlerts $alerts,
        private readonly DaemonJobQueue $queue,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function check(Machine $machine, array &$state, bool $maintenance = false): int
    {
        $inventory = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (is_array($service) && is_string($service['key'] ?? null) && preg_match('/^(docker|systemd):/', $service['key']) === 1
                && ! AppReleases::retired($machine, $service) && ! ServiceLabels::isHidden($machine, $service)) {
                $inventory[$service['key']] = $service;
            }
        }
        if ($inventory === []) {
            return 0;
        }

        $sent = 0;
        $now = now()->getTimestamp();
        $stats = $machine->service_stats ?? [];

        $this->askForScanIfMissing($machine, $inventory, $stats, $state, $now);

        $running = array_keys(array_filter($inventory, fn (array $s) => in_array($s['status'] ?? null, ['running', 'restarting'], true)));
        $previous = $state['running'] ?? null;
        $state['down'] ??= [];

        if (is_array($previous) && ! $maintenance) {
            foreach (array_diff($previous, $running) as $key) {
                $service = $inventory[$key] ?? null;
                if ($service === null || ! in_array($service['status'] ?? null, ['stopped', 'failed'], true) || isset($state['down'][$key])) {
                    continue;
                }
                if ($this->stoppedOnPurpose($machine, $key) || self::replacedSwarmTask($inventory, $running, $key)) {
                    continue;
                }

                $state['down'][$key] = $now;
                $name = self::label($machine, $service, $key);
                Activity::system($machine->workspace_id, 'service.down', $name, $machine, ['status' => $service['status'], 'key' => $key]);
                $this->alert($machine, $key, $name, 'service.down', "{$name} stopped on {$machine->name}", [
                    $service['status'] === 'failed'
                        ? 'It was running and has now failed.'
                        : 'It was running and has now stopped, and nobody stopped it from ServerOS.',
                    'Its logs usually say why: a crash, running out of memory, or a bad config after a change.',
                ], true);
                $sent++;
            }
        }

        foreach ($state['down'] as $key => $since) {
            if (! isset($inventory[$key]) || self::replacedSwarmTask($inventory, $running, $key)) {
                unset($state['down'][$key]);
            } elseif (in_array($key, $running, true)) {
                unset($state['down'][$key]);
                $name = self::label($machine, $inventory[$key], $key);
                Activity::system($machine->workspace_id, 'service.up', $name, $machine, ['seconds' => $now - $since, 'key' => $key]);
                if ($maintenance) {
                    continue;
                }
                $this->alert($machine, $key, $name, 'service.up', "{$name} is running again on {$machine->name}", [
                    'Back after '.MonitorAlerts::duration($now - $since).'.',
                ], false);
                $sent++;
            }
        }
        if (! $maintenance || ! is_array($previous)) {
            $state['running'] = $running;
        }

        $state['restarts'] ??= [];
        $state['looping'] ??= [];
        foreach ($stats as $key => $stat) {
            $count = is_int($stat['restarts'] ?? null) ? $stat['restarts'] : null;
            if ($count === null || ! isset($inventory[$key])) {
                continue;
            }

            $baseline = $state['restarts'][$key] ?? null;
            if (! is_array($baseline) || ! is_int($baseline[0] ?? null) || ! is_int($baseline[1] ?? null) || $count < $baseline[0] || $maintenance) {
                $state['restarts'][$key] = [$count, $now];

                continue;
            }
            [$base, $at] = $baseline;
            $looping = isset($state['looping'][$key]);

            if ($count - $base >= self::LOOP_RESTARTS && $now - $at <= self::LOOP_WINDOW_SECONDS) {
                $state['restarts'][$key] = [$count, $now];
                if ($looping) {
                    continue;
                }

                $state['looping'][$key] = $now;
                $name = self::label($machine, $inventory[$key], $key);
                $restarts = $count - $base;
                Activity::system($machine->workspace_id, 'service.crash_loop', $name, $machine, ['restarts' => $restarts, 'key' => $key]);
                $this->alert($machine, $key, $name, 'service.crash_loop', "{$name} keeps restarting on {$machine->name}", [
                    "It restarted {$restarts} times in ".MonitorAlerts::duration(max(60, $now - $at)).': it starts, crashes and is started again.',
                    'The error is in its logs, usually in the last lines before each restart.',
                ], true);
                $sent++;
            } elseif ($count === $base && $looping && $now - $at >= self::LOOP_SETTLED_SECONDS) {
                unset($state['looping'][$key]);
                $state['restarts'][$key] = [$count, $now];
            } elseif ($now - $at > self::LOOP_WINDOW_SECONDS && ! ($looping && $count === $base)) {
                $state['restarts'][$key] = [$count, $now];
            }
        }
        foreach (array_keys($state['restarts']) as $key) {
            if (! isset($inventory[$key])) {
                unset($state['restarts'][$key], $state['looping'][$key]);
            }
        }

        return $sent;
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventory
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $state
     */
    private function askForScanIfMissing(Machine $machine, array $inventory, array $stats, array &$state, int $now): void
    {
        $latest = 0;
        foreach ($stats as $stat) {
            $latest = max($latest, is_array($stat) ? (int) ($stat['at'] ?? 0) : 0);
        }
        if ($latest === 0 || $now - ($state['rescan_at'] ?? 0) < self::RESCAN_EVERY_SECONDS) {
            return;
        }

        $missing = false;
        foreach ($inventory as $key => $service) {
            if (($service['status'] ?? null) === 'running' && (! isset($stats[$key]) || (int) ($stats[$key]['at'] ?? 0) < $latest - 120)) {
                $missing = true;
                break;
            }
        }
        if (! $missing) {
            return;
        }

        $owner = $machine->workspace->owner;
        if ($owner === null || $this->queue->unavailable($machine) !== null) {
            return;
        }

        $state['rescan_at'] = $now;
        try {
            $this->queue->queue($machine, $owner, 'discover', ['type' => 'discover'], null, 120);
        } catch (RuntimeException) {
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventory
     * @param  list<string>  $running
     */
    public static function replacedSwarmTask(array $inventory, array $running, string $key): bool
    {
        $slot = self::swarmSlot($inventory[$key]['name'] ?? null);
        if ($slot === null) {
            return false;
        }

        foreach ($running as $other) {
            if ($other !== $key && self::swarmSlot($inventory[$other]['name'] ?? null) === $slot) {
                return true;
            }
        }

        return false;
    }

    private static function swarmSlot(mixed $name): ?string
    {
        return is_string($name) && preg_match('/^\/?(.+\.(?:\d+|[a-z0-9]{25}))\.[a-z0-9]{25}$/', $name, $match) === 1 ? $match[1] : null;
    }

    private function stoppedOnPurpose(Machine $machine, string $key): bool
    {
        $job = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('service', $key)
            ->whereIn('kind', ['service_action', 'service_remove'])
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->first();

        return $job !== null && ($job->kind === 'service_remove' || ($job->command['job']['action'] ?? null) === 'stop');
    }

    /**
     * @param  list<string>  $lines
     */
    private function alert(Machine $machine, string $key, string $name, string $event, string $subject, array $lines, bool $bad): void
    {
        $url = 'https://'.$machine->workspace->host().'/machines/'.$machine->id.'/services/'.$key;
        $this->alerts->notify($machine->workspace, $event, $subject, $lines, $url, $bad, 'Open the service', [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'service' => ['key' => $key, 'name' => $name],
        ]);
    }

    /** @param  array<string, mixed>  $service */
    private static function label(Machine $machine, array $service, string $key): string
    {
        return ServiceLabels::display($machine, $service) ?: $key;
    }
}
