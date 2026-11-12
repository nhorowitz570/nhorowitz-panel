<?php

namespace App\Support\Logs;

use App\Models\LogEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** @phpstan-type Scope array{service: string|null, level: string|null, q: string|null, since: int|null, ids: list<int>|null} */
final class LogDeletion
{
    public const CHUNK = 2000;

    public const SYNC_LIMIT = 20_000;

    /**
     * @param  Scope  $scope
     * @return Builder<LogEntry>
     */
    public static function query(int $machineId, array $scope, int $upTo, ?string $connection = null): Builder
    {
        $query = LogEntry::on($connection ?? LogStore::ours())->where('machine_id', $machineId)->where('id', '<=', $upTo);
        if ($scope['ids'] !== null) {
            return $query->whereIn('id', $scope['ids']);
        }

        return $query
            ->when($scope['service'], fn (Builder $q, string $service) => $q->where('service', $service))
            ->when($scope['level'], fn (Builder $q, string $level) => $q->whereIn('level', $level === 'error' ? ['error'] : ['error', 'warn']))
            ->when($scope['since'], fn (Builder $q, int $since) => $q->where('logged_at', '>=', $since))
            ->when($scope['q'], fn (Builder $q, string $text) => $q->whereLike('message', '%'.addcslashes($text, '%_\\').'%'));
    }

    /**
     * @param  list<int>  $machineIds
     * @param  Scope  $scope
     */
    public static function count(array $machineIds, array $scope, int $upTo, ?string $connection = null): int
    {
        return array_sum(array_map(fn (int $id) => self::query($id, $scope, $upTo, $connection)->count(), $machineIds));
    }

    /**
     * @param  list<int>  $machineIds
     * @param  Scope  $scope
     */
    public static function run(array $machineIds, array $scope, int $upTo, ?string $connection = null): int
    {
        $connection ??= LogStore::ours();
        $deleted = 0;

        foreach ($machineIds as $machineId) {
            $after = 0;
            do {
                $rows = self::query($machineId, $scope, $upTo, $connection)
                    ->where('id', '>', $after)
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->get(['id', 'machine_id', 'service', 'logged_at', 'level']);
                if ($rows->isEmpty()) {
                    break;
                }
                $after = $rows->max('id');

                DB::connection($connection)->transaction(function () use ($rows, $connection, &$deleted): void {
                    $deleted += LogEntry::on($connection)->whereIn('id', $rows->modelKeys())->delete();
                    LogCounts::subtract(array_values($rows->map(fn (LogEntry $row) => [
                        'machine_id' => $row->machine_id,
                        'service' => $row->service,
                        'logged_at' => $row->logged_at,
                        'level' => $row->level,
                    ])->all()), $connection);
                });
            } while ($rows->count() === self::CHUNK);
        }

        return $deleted;
    }
}
