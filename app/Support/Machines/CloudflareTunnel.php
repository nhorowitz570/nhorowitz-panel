<?php

namespace App\Support\Machines;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Edition;

/**
 * A machine's apps served through a Cloudflare Tunnel: cloudflared runs on
 * the machine with the tunnel's token, Cloudflare does HTTPS, and the
 * machine's proxy answers each app's domain over plain HTTP. The daemon
 * does the work (its `tunnel` job); the panel keeps what it reported.
 */
class CloudflareTunnel
{
    public const string KIND = 'tunnel';

    /** Self-hosted installs only, on a daemon that knows the job. */
    public static function offered(): bool
    {
        return Edition::selfHosted();
    }

    public static function supported(Machine $machine): bool
    {
        return DaemonJobQueue::canRunTunnel($machine);
    }

    /**
     * The tunnel's id from its token, or null when it isn't one. A token is
     * base64 JSON with the account (a), the tunnel (t) and its secret (s).
     */
    public static function tunnelId(string $token): ?string
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 4096 || preg_match('/^[A-Za-z0-9+\/=_-]+$/', $token) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data) || ! is_string($data['a'] ?? null) || ! is_string($data['s'] ?? null) || ! is_string($data['t'] ?? null)) {
            return null;
        }

        $id = strtolower($data['t']);

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1 ? $id : null;
    }

    /** Where DNS for an app on the machine points: the tunnel's own name. */
    public static function target(Machine $machine): ?string
    {
        $id = $machine->tunnel['tunnel_id'] ?? null;

        return is_string($id) && $id !== '' ? "{$id}.cfargotunnel.com" : null;
    }

    public static function active(Machine $machine): bool
    {
        return ($machine->tunnel['state'] ?? null) === 'connected';
    }

    /** The job that's changing the tunnel right now, if any. */
    public static function changing(Machine $machine): ?DaemonJob
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', self::KIND)
            ->whereIn('state', ['queued', 'running'])
            ->latest()
            ->get()
            ->first(fn (DaemonJob $job) => ! $job->isStale());
    }

    /**
     * What the daemon reported when the job finished: kept on the machine.
     *
     * @param  array<string, mixed>  $result
     * @return array{provider: string, tunnel_id: string|null, state: string, plain_http: bool, since: string}|null
     */
    public static function fromResult(array $result): ?array
    {
        $tunnel = $result['tunnel'] ?? null;
        if (! is_array($tunnel) || ($tunnel['state'] ?? null) !== 'connected') {
            return null;
        }
        $id = is_string($tunnel['tunnel_id'] ?? null) ? strtolower($tunnel['tunnel_id']) : null;

        return [
            'provider' => 'cloudflare',
            'tunnel_id' => $id !== null && preg_match('/^[0-9a-f-]{36}$/', $id) === 1 ? $id : null,
            'state' => 'connected',
            'plain_http' => (bool) ($tunnel['plain_http'] ?? false),
            'since' => now()->toIso8601String(),
        ];
    }
}
