<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property int $machine_id
 * @property string $service
 * @property int $ts
 * @property float|null $cpu
 * @property int|null $mem_used
 * @property int|null $net_rx
 * @property int|null $net_tx
 * @property int|null $pids
 */
class ServiceSample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const KEEP_SECONDS = 86400;

    public const EVERY_SECONDS = 15;

    public const FULL_SECONDS = 3600;

    private const THINNED_UNTIL = 'service-samples:thinned-until';

    public static function due(Machine $machine): bool
    {
        return Cache::add('service-samples:'.$machine->id, true, self::EVERY_SECONDS);
    }

    public static function prune(): int
    {
        $now = now()->getTimestamp();
        $deleted = self::query()->where('ts', '<', $now - self::KEEP_SECONDS)->delete();

        $until = intdiv($now - self::FULL_SECONDS, 60) * 60;
        $from = max(intdiv($now - self::KEEP_SECONDS, 60) * 60, (int) Cache::get(self::THINNED_UNTIL, 0));
        for ($start = $from; $start < $until; $start += 3600) {
            $end = min($start + 3600, $until);
            $extra = self::query()
                ->selectRaw('id, row_number() over (partition by machine_id, service, ts / 60 order by ts, id) as nth')
                ->where('ts', '>=', $start)
                ->where('ts', '<', $end);

            $deleted += self::query()
                ->whereIn('id', fn ($query) => $query->fromSub($extra, 'readings')->where('nth', '>', 1)->select('id'))
                ->delete();
            Cache::forever(self::THINNED_UNTIL, $end);
        }

        return $deleted;
    }
}
