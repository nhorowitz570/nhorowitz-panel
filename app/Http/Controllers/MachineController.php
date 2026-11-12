<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePolicy;
use App\Models\MachineSample;
use App\Models\MaintenanceWindow;
use App\Models\ServiceGroup;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\ConsoleLock;
use App\Support\Deploys\DeployFailure;
use App\Support\Files\FileJobs;
use App\Support\Firewall;
use App\Support\Health\WorkspaceHealth;
use App\Support\Machines\MachinePolicies;
use App\Support\ScheduledTasks;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MachineController extends Controller
{
    private const RANGES = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public function index(Workspace $workspace): Response
    {
        return Inertia::render('workspace/Machines', [
            'workspace' => $workspace->toPageProps(),
            'machines' => MachineResource::collection(
                $workspace->machines()->orderBy('name')->get(),
            )->resolve(),
            'machineGroups' => $workspace->machineGroups()->orderBy('name')->get()
                ->map(fn (MachineGroup $group) => ['id' => $group->id, 'name' => $group->name, 'policyId' => $group->machine_policy_id])
                ->all(),
            'machinePolicies' => tap($workspace->machinePolicies(), fn () => MachinePolicies::defaultFor($workspace))->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default'])
                ->map(fn (MachinePolicy $policy) => ['id' => $policy->id, 'name' => $policy->name, 'isDefault' => $policy->is_default])
                ->all(),
            'fleetStatus' => ['live' => (bool) $workspace->fleetStatusPage?->live()],
        ]);
    }

    public function show(Request $request, Workspace $workspace, Machine $machine, ServiceTemplateCatalog $templates, ConsoleLock $console): Response
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        $range = array_key_exists((string) $request->query('range'), self::RANGES)
            ? (string) $request->query('range')
            : '24h';
        $seesConsole = Gate::allows('manageMachines', $workspace);

        return Inertia::render('workspace/MachineShow', [
            'workspace' => $workspace->toPageProps(),
            'machine' => [
                ...(new MachineResource($machine))->resolve(),
                'firewall' => Firewall::view($machine),
                'serviceStats' => (object) ($machine->service_stats ?? []),
                'plannedMaintenance' => MaintenanceWindow::query()
                    ->where('machine_id', $machine->id)
                    ->whereIn('state', ['scheduled', 'active'])
                    ->with('creator:id,name')
                    ->orderBy('starts_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (MaintenanceWindow $w) => $w->toCard())
                    ->all(),
            ],
            'recent' => fn () => MachineSample::history($machine, 3600),
            'range' => $range,
            'history' => Inertia::optional(fn () => MachineSample::history($machine, self::RANGES[$range])),
            'scheduled' => fn () => ScheduledTasks::for($machine),
            'issues' => fn () => app(WorkspaceHealth::class)->forMachine($machine),
            'serviceGroups' => fn () => $machine->serviceGroups()->orderBy('name')->get()
                ->map(fn (ServiceGroup $group) => $group->toPageProps())
                ->all(),
            'templates' => fn () => $templates->toPageProps($machine),
            'deployEnabled' => (bool) config('daemon.commands_enabled') && filled(config('daemon.gateway_internal_url')),
            'deployWorkflows' => fn () => [
                'available' => $workspace->hasAutomations(),
                'count' => $workspace->workflows()->whereIn('trigger', ['webhook', 'deploy.finished'])->count(),
            ],
            'jobs' => DaemonJob::query()
                ->where('machine_id', $machine->id)
                ->whereNotIn('kind', ['discover', ...FileJobs::KINDS])
                ->latest()
                ->limit(20)
                ->get()
                ->map(function (DaemonJob $job) use ($seesConsole) {
                    $hidden = $job->kind === 'service_exec' && ! $seesConsole;

                    return [
                        'id' => $job->id,
                        'kind' => $job->kind,
                        'service' => $job->service,
                        'command' => $job->kind === 'service_exec' && ! $hidden ? ($job->command['job']['command'] ?? null) : null,
                        'state' => $job->state,
                        'phase' => $job->phase,
                        'progress' => $job->progress,
                        'log' => $hidden ? [] : ($job->log ?? []),
                        'result' => $hidden ? null : $job->result,
                        'error' => $job->error,
                        'explanation' => DeployFailure::explain($job),
                        'createdAt' => $job->created_at?->toIso8601String(),
                        'updatedAt' => $job->updated_at?->toIso8601String(),
                        'retryable' => $job->isRetryable(),
                        'stale' => $job->isStale(),
                    ];
                }),
            'files' => fn () => [
                'places' => FileJobs::places($machine),
                'maxWrite' => FileJobs::MAX_WRITE_BYTES,
                'maxRead' => FileJobs::MAX_READ_BYTES,
            ],
            'consoleLock' => fn () => [
                'twoFactorEnabled' => ! $console->requiresSetup($request->user()),
                'unlockedUntil' => $console->unlockedUntil()?->toIso8601String(),
                'minutes' => ConsoleLock::MINUTES,
            ],
        ]);
    }

    #[Audited]
    public function destroy(Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $name = $machine->name;
        Activity::record($workspace, 'machine.remove', $name);
        $machine->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$name}."]);

        return to_route('workspace.machines.index', $workspace->slug);
    }
}
