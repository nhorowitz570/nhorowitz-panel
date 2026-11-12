<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Models\Monitor;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

final class Monitors
{
    public const STATUSES = ['up', 'down', 'pending', 'paused'];

    public static function find(McpContext $context, mixed $reference): Monitor
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new ToolError('Say which monitor: its id or name. list_monitors shows them.');
        }

        $query = $context->workspace->monitors()->with('machine');
        if (ctype_digit($reference) && ($monitor = (clone $query)->find((int) $reference)) !== null) {
            return $monitor;
        }

        $matches = (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several monitors are called “{$reference}”: ".$matches->map(fn (Monitor $m) => "#{$m->id} ({$m->target()})")->implode(', ').'. Use the id.');
        }

        $close = (clone $query)->whereRaw('lower(name) like ?', ['%'.mb_strtolower($reference).'%'])->orderBy('name')->limit(5)->pluck('name');

        throw new ToolError("No monitor “{$reference}” in {$context->workspace->name}.".($close->isNotEmpty() ? ' Did you mean: '.$close->implode(', ').'?' : ' list_monitors shows them.'));
    }

    /** @return array<string, mixed> */
    public static function row(Monitor $monitor, McpContext $context): array
    {
        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'type' => $monitor->type,
            'target' => $monitor->target(),
            'status' => $monitor->paused ? 'paused' : $monitor->status,
            'since' => $monitor->status_changed_at?->toIso8601String(),
            'lastCheckedAt' => $monitor->last_checked_at?->toIso8601String(),
            'responseMs' => $monitor->last_response_ms,
            'error' => $monitor->last_error,
            'uptime' => [
                'last7Days' => $monitor->uptime(7),
                'last30Days' => $monitor->uptime(30),
            ],
            'intervalSeconds' => $monitor->interval_seconds,
            'paused' => $monitor->paused,
            'alerts' => self::alerts($monitor),
            'certificateExpiresAt' => $monitor->isHttps() ? $monitor->ssl_expires_at?->toIso8601String() : null,
            'machine' => self::machine($monitor, $context),
            'url' => $context->url("/monitors/{$monitor->id}"),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Monitor $monitor, McpContext $context): array
    {
        return [
            ...self::row($monitor, $context),
            'settings' => [
                'type' => $monitor->type,
                'url' => $monitor->url,
                'host' => $monitor->host,
                'port' => $monitor->port,
                'intervalSeconds' => $monitor->interval_seconds,
                'timeoutSeconds' => $monitor->timeout_seconds,
                'expectedStatus' => $monitor->expected_status,
                'keyword' => $monitor->keyword,
                'machineId' => $monitor->machine_id,
                'alerts' => $monitor->alerts,
            ],
            'createdAt' => $monitor->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function alerts(Monitor $monitor): array
    {
        $maintenance = $monitor->machine_id !== null && $monitor->machine?->inMaintenance();

        return [
            'enabled' => $monitor->alerts,
            'muted' => $monitor->muted(),
            'mutedUntil' => $monitor->muted_until?->isFuture() ? $monitor->muted_until->toIso8601String() : null,
            'machineMaintenanceUntil' => $maintenance ? $monitor->machine->maintenance_until->toIso8601String() : null,
        ];
    }

    /** @return array{id: int, name: string, url: string}|null */
    public static function machine(Monitor $monitor, McpContext $context): ?array
    {
        $machine = $monitor->machine_id !== null ? $monitor->machine : null;

        return $machine instanceof Machine
            ? ['id' => $machine->id, 'name' => $machine->name, 'url' => $context->url("/machines/{$machine->id}")]
            : null;
    }

    /** @param  array<string, mixed>  $arguments */
    public static function confirm(array $arguments, Monitor $monitor): void
    {
        $given = $arguments['confirm'] ?? null;
        if (! is_string($given) || trim($given) === '') {
            throw new ToolError("This can’t be undone. Check with the person first, then call again with confirm set to the monitor’s name exactly: “{$monitor->name}”.");
        }
        if (trim($given) !== $monitor->name) {
            throw new ToolError("confirm doesn’t match. It must be the monitor’s name exactly: “{$monitor->name}”. Nothing was changed.");
        }
    }

    public static function throttle(McpContext $context, string $action, int $perMinute, string $message): void
    {
        $key = "mcp-monitors:{$action}:{$context->workspace->id}:{$context->user->id}";
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw new ToolError($message.' Try again in '.RateLimiter::availableIn($key).' s.');
        }
        RateLimiter::hit($key, 60);
    }

    /** @return Closure(string, mixed, Closure): void */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an id or a name.");
            }
        };
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return Schema::string('The monitor: its id or its name (as list_monitors shows).', maxLength: 120);
    }
}
