<?php

namespace App\Support\Mcp;

use App\Enums\Currency;
use App\Models\McpCallPack;
use App\Models\Workspace;
use App\Support\Extensions\Prices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class McpUsage
{
    public const int PACK_CALLS = 1000;

    public const int MAX_PACKS = 10;

    public static function period(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m');
    }

    public static function resetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfMonth()->addMonth();
    }

    public static function used(Workspace $workspace): int
    {
        return (int) DB::table('mcp_usage')
            ->where('workspace_id', $workspace->id)
            ->where('period', self::period())
            ->value('calls');
    }

    public static function extra(Workspace $workspace): int
    {
        return (int) McpCallPack::query()
            ->where('workspace_id', $workspace->id)
            ->where('period', self::period())
            ->sum('calls');
    }

    public static function limit(Workspace $workspace): ?int
    {
        $included = $workspace->mcpCallLimit();

        return $included === null ? null : $included + self::extra($workspace);
    }

    public static function claim(Workspace $workspace): ?string
    {
        $period = self::period();
        $now = now();
        $limit = self::limit($workspace);

        DB::table('mcp_usage')->insertOrIgnore(['workspace_id' => $workspace->id, 'period' => $period, 'calls' => 0, 'created_at' => $now, 'updated_at' => $now]);

        $claimed = DB::table('mcp_usage')
            ->where('workspace_id', $workspace->id)
            ->where('period', $period)
            ->when($limit !== null, fn ($q) => $q->where('calls', '<', $limit))
            ->increment('calls', 1, ['updated_at' => $now]);

        return $claimed === 1 ? $period : null;
    }

    public static function release(Workspace $workspace, string $period): void
    {
        DB::table('mcp_usage')
            ->where('workspace_id', $workspace->id)
            ->where('period', $period)
            ->where('calls', '>', 0)
            ->decrement('calls', 1, ['updated_at' => now()]);
    }

    /** @return array{used: int, included: int|null, extra: int, limit: int|null, unlimited: bool, resetsAt: string, packCalls: int, packPrice: int|null, currency: string, maxPacks: int} */
    public static function summary(Workspace $workspace, Currency $currency = Currency::GBP): array
    {
        $included = $workspace->mcpCallLimit();
        $extra = self::extra($workspace);

        return [
            'used' => self::used($workspace),
            'included' => $included,
            'extra' => $extra,
            'limit' => $included === null ? null : $included + $extra,
            'unlimited' => $included === null,
            'resetsAt' => self::resetsAt()->toIso8601String(),
            'packCalls' => self::PACK_CALLS,
            'packPrice' => app(Prices::class)->mcpPack($currency),
            'currency' => $currency->value,
            'maxPacks' => self::MAX_PACKS,
        ];
    }
}
