<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Machines\CloudflareTunnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Serving a machine's apps through a Cloudflare Tunnel (self-hosted only):
 * the token goes to the daemon, which runs cloudflared with it and switches
 * the machine's proxy to plain HTTP, since Cloudflare does HTTPS.
 */
class MachineTunnelController extends Controller
{
    #[Audited]
    public function store(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        $token = preg_replace('/\s+/', '', $data['token']) ?? '';
        // Pasted the whole install command: the token is its last word.
        if (str_contains($data['token'], 'cloudflared')) {
            $words = preg_split('/\s+/', trim($data['token'])) ?: [];
            $token = (string) end($words);
        }
        if (CloudflareTunnel::tunnelId($token) === null) {
            throw ValidationException::withMessages(['token' => __('That isn’t a Cloudflare Tunnel token. Copy it from the tunnel’s install command in Cloudflare (Networking › Tunnels).')]);
        }

        return $this->send($workspace, $machine, $queue, $request, ['type' => 'tunnel', 'action' => 'up', 'token' => $token]);
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);

        return $this->send($workspace, $machine, $queue, $request, ['type' => 'tunnel', 'action' => 'down']);
    }

    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === CloudflareTunnel::KIND, 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'phase' => $job->phase,
            'error' => $job->error,
            'tunnel' => $machine->fresh()?->tunnel,
        ]);
    }

    private function authorizeFor(Workspace $workspace, Machine $machine): void
    {
        abort_unless($machine->workspace_id === $workspace->id && CloudflareTunnel::offered(), 404);
        Gate::authorize('manageMachines', $workspace);
    }

    /** @param  array{type: string, action: string, token?: string}  $job */
    private function send(Workspace $workspace, Machine $machine, DaemonJobQueue $queue, Request $request, array $job): JsonResponse
    {
        if (! CloudflareTunnel::supported($machine)) {
            return response()->json(['error' => __('Update the ServerOS daemon on :name to :version or later first.', ['name' => $machine->name, 'version' => config('daemon.tunnel_since')])], 409);
        }
        if (CloudflareTunnel::changing($machine) !== null) {
            return response()->json(['error' => __('The tunnel is already being changed on this machine. Try again in a moment.')], 409);
        }
        if (($reason = $queue->unavailable($machine)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            // Installing cloudflared can take a few minutes on a slow mirror.
            $record = $queue->queue($machine, $request->user(), CloudflareTunnel::KIND, $job, null, 600);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        Activity::record($workspace, 'machine.tunnel', $machine->name, $machine, meta: ['action' => $job['action']]);

        return response()->json(['jobId' => $record->id], 202);
    }
}
