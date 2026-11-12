<?php

namespace App\Support\Logs;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class LogQuota
{
    public static function take(Workspace $workspace, int $lines): int
    {
        if ($lines <= 0) {
            return 0;
        }
        if (LogStore::isExternal($workspace)) {
            return $lines;
        }

        $day = self::day();
        $kept = self::key($workspace, 'kept', $day);
        Cache::add($kept, 0, now()->addDays(2));
        $total = (int) Cache::increment($kept, $lines);
        $room = max(0, $lines - max(0, $total - $workspace->logLinesPerDay()));

        if ($room < $lines) {
            Cache::decrement($kept, $lines - $room);
            $dropped = self::key($workspace, 'dropped', $day);
            Cache::add($dropped, 0, now()->addDays(2));
            Cache::increment($dropped, $lines - $room);
            Cache::add(self::key($workspace, 'full-at', $day), now()->getTimestamp(), now()->addDays(2));
        }

        return $room;
    }

    /** @return array{limit: int, kept: int, dropped: int, fullAt: string|null, resetsAt: string, exempt: bool} */
    public static function today(Workspace $workspace): array
    {
        $day = self::day();
        $fullAt = Cache::get(self::key($workspace, 'full-at', $day));

        return [
            'limit' => $workspace->logLinesPerDay(),
            'kept' => (int) Cache::get(self::key($workspace, 'kept', $day), 0),
            'dropped' => (int) Cache::get(self::key($workspace, 'dropped', $day), 0),
            'fullAt' => is_numeric($fullAt) ? CarbonImmutable::createFromTimestamp((int) $fullAt)->toIso8601String() : null,
            'resetsAt' => CarbonImmutable::now('UTC')->addDay()->startOfDay()->toIso8601String(),
            'exempt' => LogStore::isExternal($workspace),
        ];
    }

    public static function keptToday(Workspace|int $workspace): int
    {
        return (int) Cache::get(self::key($workspace, 'kept', self::day()), 0);
    }

    public static function droppedToday(Workspace|int $workspace): int
    {
        return (int) Cache::get(self::key($workspace, 'dropped', self::day()), 0);
    }

    private static function day(): string
    {
        return CarbonImmutable::now('UTC')->format('Ymd');
    }

    private static function key(Workspace|int $workspace, string $what, string $day): string
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return "log-quota:{$id}:{$day}:{$what}";
    }
}
