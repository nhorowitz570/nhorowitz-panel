<?php

namespace App\Support\Stats;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class Visits
{
    public static function record(int $workspaceId, int $userId, string $source = 'panel'): void
    {
        $day = now()->toDateString();

        try {
            if (! Cache::add("visit:{$workspaceId}:{$userId}:{$day}", true, now()->endOfDay())) {
                return;
            }

            DB::table('workspace_visits')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'day' => $day,
                'source' => $source,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
