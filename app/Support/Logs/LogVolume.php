<?php

namespace App\Support\Logs;

use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use Illuminate\Support\Facades\DB;

final class LogVolume
{
    /**
     * @return array{bytes: int|null, ownStorage: int, rows: list<array{id: int, name: string, plan: string|null, lines: int, top: string|null, topLines: int, limit: int, kept: int, dropped: int}>}
     */
    public static function summary(int $limit = 15): array
    {
        $since = (int) (now()->subDay()->getTimestamp() * 1000);
        $own = WorkspaceLogStore::query()->with('workspace')->where('enabled', true)->get()
            ->filter(fn (WorkspaceLogStore $store) => $store->workspace->hasOwnLogStorage())
            ->pluck('workspace_id')->values()->all();
        $byService = DB::connection(LogStore::ours())->table('log_counts')
            ->join('machines', 'machines.id', '=', 'log_counts.machine_id')
            ->where('log_counts.minute', '>=', $since)
            ->whereNotIn('machines.workspace_id', $own)
            ->groupBy('machines.workspace_id', 'log_counts.service')
            ->select('machines.workspace_id', 'log_counts.service', DB::raw('sum(log_counts.n) as line_count'))
            ->get()
            ->groupBy('workspace_id');

        $totals = $byService->map(fn ($services) => (int) $services->sum('line_count'))->sortDesc()->take($limit);
        $workspaces = Workspace::query()->whereIn('id', $totals->keys())->get()->keyBy('id');

        $rows = [];
        foreach ($totals as $id => $lines) {
            $workspace = $workspaces->get($id);
            if ($workspace === null) {
                continue;
            }
            $top = $byService->get($id)->sortByDesc(fn ($service) => (int) $service->line_count)->first();
            $today = LogQuota::today($workspace);
            $rows[] = [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'plan' => $workspace->effectivePlan()?->value,
                'lines' => $lines,
                'top' => $top?->service,
                'topLines' => (int) ($top->line_count ?? 0),
                'limit' => $today['limit'],
                'kept' => $today['kept'],
                'dropped' => $today['dropped'],
            ];
        }

        return ['bytes' => self::bytes(), 'ownStorage' => count($own), 'rows' => $rows];
    }

    private static function bytes(): ?int
    {
        if (DB::getDriverName() !== 'pgsql') {
            return null;
        }

        return (int) DB::scalar("select pg_total_relation_size('log_entries')");
    }
}
