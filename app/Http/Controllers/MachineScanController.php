<?php

namespace App\Http\Controllers;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use App\Support\DaemonJobQueue;
use App\Support\SecurityScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class MachineScanController extends Controller
{
    #[NotAudited('Asks the machine to look again now; nothing changes')]
    public function discover(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        $scannedAt = $machine->inventory['scanned_at'] ?? null;

        if (($reason = $queue->unavailable($machine)) !== null) {
            return response()->json(['jobId' => null, 'scannedAt' => $scannedAt, 'note' => $reason]);
        }

        $job = $queue->rescan($machine, $request->user());
        if ($job === null) {
            return response()->json(['jobId' => null, 'scannedAt' => $scannedAt, 'note' => 'Couldn’t reach the machine. Showing the last scan.']);
        }

        return response()->json(['jobId' => $job->id, 'scannedAt' => $scannedAt], 202);
    }

    #[NotAudited('A check for updates; installing them is recorded')]
    public function store(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if (($reason = $queue->unavailable($machine)) !== null) {
            return response()->json(['jobId' => null, 'note' => $reason]);
        }

        try {
            $job = $queue->queue($machine, $request->user(), 'package_updates', [
                'type' => 'package_updates', 'apply' => false, 'security_only' => false,
            ], null, 180);
        } catch (RuntimeException) {
            return response()->json(['jobId' => null]);
        }

        return response()->json(['jobId' => $job->id], 202);
    }

    public function show(Request $request, Workspace $workspace, Machine $machine, SecurityScan $scan): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        $job = null;
        if (is_string($request->query('job'))) {
            $job = DaemonJob::query()
                ->where('machine_id', $machine->id)
                ->where('kind', 'package_updates')
                ->find($request->query('job'));
        }

        $report = $scan->report($machine, $job);

        if ($job === null && ! $request->filled('job')) {
            $report['checks'] = array_map(fn (array $c) => $c['key'] === 'updates' && $c['state'] === 'scanning'
                ? [...$c, 'state' => 'unknown', 'summary' => 'Not checked: commands are off on this panel']
                : $c, $report['checks']);
            $report['finished'] = collect($report['checks'])->every(fn (array $c) => $c['state'] !== 'scanning');
        }

        return response()->json($report)->header('Cache-Control', 'no-store');
    }
}
