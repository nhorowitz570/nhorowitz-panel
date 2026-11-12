<?php

namespace App\Support\Logs;

use App\Models\LogEntry;
use App\Models\Machine;
use App\Support\Deploys\ServiceLabels;
use App\Support\Machines\MachinePolicies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** @phpstan-type LogRow array{machine_id: int, service_key: string, service: string, logged_at: int, level: string, stream: string, message: string} */
final class LogIngest
{
    public const MAX_LINES = 1000;

    public const MAX_MESSAGE = 8192;

    public const CHUNK = 250;

    private const DOWN_SECONDS = 60;

    private const OK_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function store(Machine $machine, array $payload): int
    {
        $lines = $payload['lines'] ?? null;
        if (! is_array($lines)) {
            return 0;
        }
        if (! MachinePolicies::for($machine)->logs) {
            return 0;
        }

        $now = (int) floor(microtime(true) * 1000);
        $services = self::inventoryServices($machine);
        $muted = $machine->log_muted_services ?? [];
        $rows = [];

        foreach (array_slice($lines, 0, self::MAX_LINES) as $line) {
            if (! is_array($line) || ! is_string($line['service'] ?? null) || ! is_string($line['line'] ?? null)) {
                continue;
            }

            $message = mb_strcut($line['line'], 0, self::MAX_MESSAGE, 'UTF-8');
            if (trim($message) === '') {
                continue;
            }

            $priority = is_int($line['priority'] ?? null) ? $line['priority'] : null;
            $stream = in_array($line['stream'] ?? null, ['stdout', 'stderr', 'journal'], true) ? $line['stream'] : 'stdout';
            $at = is_int($line['ts'] ?? null) && abs($line['ts'] - $now) < 86_400_000 ? $line['ts'] : $now;

            $key = substr($line['service'], 0, 300);
            $name = is_string($line['name'] ?? null) && $line['name'] !== '' ? $line['name'] : ($services[$key]['name'] ?? $key);
            if ($muted !== [] && in_array(ServiceLabels::identity($services[$key] ?? ['key' => $key, 'name' => $name]), $muted, true)) {
                continue;
            }

            $rows[] = [
                'machine_id' => $machine->id,
                'service_key' => $key,
                'service' => mb_substr($name, 0, 200),
                'logged_at' => $at,
                'level' => LogLevel::detect($message, $priority),
                'stream' => $stream,
                'message' => $message,
            ];
        }

        $workspace = $machine->workspace;
        if ($workspace !== null) {
            $rows = array_slice($rows, 0, LogQuota::take($workspace, count($rows)));
        }

        $connection = $workspace === null ? LogStore::ours() : LogStore::connection($workspace);
        if ($workspace === null || $connection === LogStore::ours()) {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                LogEntry::on($connection)->insert($chunk);
            }
            LogCounts::add($rows, $connection);

            return count($rows);
        }

        self::storeOwn($workspace->id, $connection, $rows);

        return count($rows);
    }

    /** @param  list<LogRow>  $rows */
    private static function storeOwn(int $workspaceId, string $connection, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if (Cache::has(self::downKey($workspaceId))) {
            LogBuffer::push($workspaceId, $rows);

            return;
        }

        $chunks = array_chunk($rows, self::CHUNK);
        foreach ($chunks as $i => $chunk) {
            try {
                self::write($connection, $chunk);
            } catch (Throwable $e) {
                LogBuffer::push($workspaceId, array_merge(...array_slice($chunks, $i)));
                self::failed($workspaceId, $e);

                return;
            }
        }
        self::answered($workspaceId);
    }

    /** @param  list<LogRow>  $rows */
    public static function write(string $connection, array $rows): void
    {
        DB::connection($connection)->transaction(function () use ($connection, $rows): void {
            LogEntry::on($connection)->insert($rows);
            LogCounts::add($rows, $connection);
        });
    }

    public static function answered(int $workspaceId): void
    {
        Cache::forget(self::downKey($workspaceId));
        if (Cache::add(self::okKey($workspaceId), true, self::OK_SECONDS)) {
            LogStore::markOk($workspaceId);
        }
    }

    public static function failed(int $workspaceId, Throwable $e): void
    {
        LogStore::markUnreachable($workspaceId, $e);
        Cache::forget(self::okKey($workspaceId));
        Cache::put(self::downKey($workspaceId), true, self::DOWN_SECONDS);
    }

    private static function downKey(int $workspaceId): string
    {
        return "log-store:{$workspaceId}:down";
    }

    private static function okKey(int $workspaceId): string
    {
        return "log-store:{$workspaceId}:ok";
    }

    /** @return array<string, array<string, mixed>> */
    private static function inventoryServices(Machine $machine): array
    {
        $services = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (is_array($service) && is_string($service['key'] ?? null) && is_string($service['name'] ?? null)) {
                $services[$service['key']] = $service;
            }
        }

        return $services;
    }
}
