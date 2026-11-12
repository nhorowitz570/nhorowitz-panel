<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Machine;
use App\Models\ServiceGroup;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Workspace $workspace, ServiceTemplateCatalog $templates): Response
    {
        $machines = $workspace->machines()->orderBy('name')->get();

        return Inertia::render('workspace/Services', [
            'workspace' => $workspace->toPageProps(),
            'services' => $machines->flatMap(fn (Machine $machine) => collect(AppReleases::visible($machine))
                ->filter(fn (array $service) => isset($service['key']))
                ->map(fn (array $service) => [
                    ...$service,
                    'machine' => ['id' => $machine->id, 'name' => $machine->name],
                    'stat' => $machine->service_stats[$service['key']] ?? null,
                ]))->values(),
            'groups' => ServiceGroup::query()->whereIn('machine_id', $machines->modelKeys())->orderBy('name')->get()
                ->map(fn (ServiceGroup $group) => [...$group->toPageProps(), 'machineId' => $group->machine_id]),
            'machines' => $machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'connection' => MachineResource::connection($machine),
                'scanned' => $machine->inventory !== null,
            ]),
            'templates' => fn () => $templates->toPageProps(),
            'deployEnabled' => (bool) config('daemon.commands_enabled') && filled(config('daemon.gateway_internal_url')),
        ]);
    }
}
