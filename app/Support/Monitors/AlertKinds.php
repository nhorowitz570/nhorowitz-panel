<?php

namespace App\Support\Monitors;

use App\Models\Workspace;

final class AlertKinds
{
    public const EVENTS = [
        'machines' => ['machine.offline', 'machine.online'],
        'resources' => ['machine.disk', 'machine.memory', 'machine.memory_ok'],
        'services' => ['service.down', 'service.up', 'service.crash_loop'],
        'deploys' => ['deploy.failed', 'deploy.recovered'],
        'backups' => ['backup.failed', 'backup.recovered'],
        'monitors' => ['monitor.down', 'monitor.up', 'certificate.expiring'],
        'workflows' => ['workflow.failed'],
        'updates' => ['daemon.update_waiting', 'daemon.updated'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::EVENTS);
    }

    public static function of(string $event): ?string
    {
        foreach (self::EVENTS as $kind => $events) {
            if (in_array($event, $events, true)) {
                return $kind;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function muted(Workspace $workspace): array
    {
        $muted = is_array($workspace->alert_muted) ? $workspace->alert_muted : [];

        return array_values(array_intersect(self::keys(), $muted));
    }

    public static function allows(Workspace $workspace, string $event): bool
    {
        $kind = self::of($event);

        return $kind === null || ! in_array($kind, self::muted($workspace), true);
    }
}
