<?php

namespace App\Mcp\Tools\Monitor;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Models\Monitor;
use App\Models\User;
use App\Support\Monitors\AlertKinds;

final class AlertSettings
{
    public const EVENTS = 'Sent when a monitor goes down or a machine stops reporting, when they’re back, when a disk is 90% full, when memory stays over 92% for 10 minutes, when a service stops or keeps crashing, when a deploy or a backup starts failing and when it works again, and two weeks before a certificate expires.';

    /** @return array<string, mixed> */
    public static function present(McpContext $context): array
    {
        $workspace = $context->workspace;
        $webhook = is_string($workspace->alert_webhook_url) && $workspace->alert_webhook_url !== '' ? $workspace->alert_webhook_url : null;
        $manager = $context->can(WorkspacePermission::ManageMachines);
        $monitors = $workspace->monitors()->with('machine')->get();

        return [
            'email' => [
                'on' => (bool) ($workspace->alert_email ?? true),
                'to' => 'The workspace’s owners and admins, each as they choose in their notification settings: every alert, only problems (not “it’s back”), or none.',
                'recipients' => $manager ? self::recipients($context) : null,
            ],
            'webhook' => [
                'set' => $webhook !== null,
                'host' => $webhook !== null && $manager ? parse_url($webhook, PHP_URL_HOST) : null,
            ],
            'events' => self::EVENTS,
            'off' => AlertKinds::muted($workspace),
            'kinds' => AlertKinds::keys(),
            'monitors' => [
                'total' => $monitors->count(),
                'alertsOff' => $monitors->filter(fn (Monitor $m) => ! $m->alerts)->count(),
                'muted' => $monitors->filter(fn (Monitor $m) => $m->alerts && $m->muted())->count(),
            ],
            'canChange' => $manager,
            'url' => $context->url('/monitors'),
        ];
    }

    /** @return list<array{name: string, role: string, gets: string}> */
    private static function recipients(McpContext $context): array
    {
        return array_values($context->workspace->members()
            ->withPivot(['role', 'alert_emails'])
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->orderBy('name')
            ->get()
            ->unique('id')
            ->map(fn (User $user) => [
                'name' => $user->name,
                'role' => $user->pivot->role,
                'gets' => match ($user->pivot->alert_emails ?? 'all') {
                    'none' => 'none',
                    'problems' => 'problems',
                    default => 'all',
                },
            ])
            ->all());
    }
}
