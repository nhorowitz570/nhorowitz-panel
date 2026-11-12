<?php

namespace App\Support\Deploys;

use App\Models\DaemonJob;
use App\Models\Machine;

final class AppReleases
{
    /** @var \WeakMap<Machine, array<string, string>>|null */
    private static ?\WeakMap $live = null;

    /** @return array{app: string, commit: string}|null */
    public static function parse(?string $containerName): ?array
    {
        if (! preg_match('/^\/?serveros-(.+)-([0-9a-f]{12})$/', (string) $containerName, $match)) {
            return null;
        }

        return ['app' => $match[1], 'commit' => $match[2]];
    }

    /** @return array<string, string> */
    public static function live(Machine $machine): array
    {
        self::$live ??= new \WeakMap;

        return self::$live[$machine] ??= DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->whereIn('kind', ['deploy', 'rollback'])
            ->where('state', 'succeeded')
            ->orderBy('created_at')
            ->get(['kind', 'service', 'command', 'result'])
            ->filter(fn (DaemonJob $job) => is_string($job->service) && ($job->kind === 'rollback'
                ? is_string($job->result['commit'] ?? null)
                : filled($job->command['job']['repo'] ?? null)))
            ->mapWithKeys(fn (DaemonJob $job) => [$job->service => substr((string) ($job->kind === 'rollback'
                ? $job->result['commit']
                : ($job->command['job']['commit'] ?? '')), 0, 12)])
            ->all();
    }

    /** @param  array<string, mixed>  $service */
    public static function retired(Machine $machine, array $service): bool
    {
        $release = self::parse($service['name'] ?? null);
        if ($release === null) {
            return false;
        }
        $live = self::live($machine)[$release['app']] ?? null;

        return $live !== null && $live !== $release['commit'];
    }

    /**
     * @param  list<array<string, mixed>>|null  $services
     * @return list<array<string, mixed>>
     */
    public static function visible(Machine $machine, ?array $services = null): array
    {
        $visible = [];
        foreach ($services ?? ($machine->inventory['services'] ?? []) as $service) {
            if (! is_array($service) || self::retired($machine, $service)) {
                continue;
            }
            $release = self::parse($service['name'] ?? null);
            if ($release !== null && isset(self::live($machine)[$release['app']])) {
                $service['app'] = $release['app'];
            }
            $service['ports'] = is_array($service['ports'] ?? null) ? array_values($service['ports']) : [];
            $service['label'] = ServiceLabels::for($machine, $service);
            $service['found'] = ContainerNames::found($service);
            $service['icon'] = ServiceLabels::iconUrl($machine, $service);
            $service['hidden'] = ServiceLabels::isHidden($machine, $service);
            $visible[] = $service;
        }

        return $visible;
    }

    /** @return array<string, mixed>|null */
    public static function find(Machine $machine, string $app): ?array
    {
        $live = self::live($machine)[$app] ?? null;
        $fallback = null;
        foreach ($machine->inventory['services'] ?? [] as $service) {
            $release = is_array($service) ? self::parse($service['name'] ?? null) : null;
            if ($release === null || $release['app'] !== $app) {
                continue;
            }
            if ($release['commit'] === $live) {
                return $service;
            }
            $fallback ??= $service;
        }

        return $fallback;
    }
}
