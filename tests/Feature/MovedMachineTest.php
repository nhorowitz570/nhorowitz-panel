<?php

use App\Enums\Plan;
use App\Enums\WorkspaceRole;
use App\Jobs\DiagnoseFix;
use App\Models\AgentFix;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MaintenanceWindow;
use App\Models\PackageRollout;
use App\Models\PackageRolloutMachine;
use App\Models\Project;
use App\Models\ProjectLink;
use App\Models\ProjectMachine;
use App\Models\ProjectNode;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Fixes\FixRunner;
use App\Support\Machines\PackageRollouts;
use App\Support\Maintenance\ScheduledMaintenance;
use App\Support\Projects\ProjectLinks;
use App\Support\Projects\ProjectNetwork;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
});

function movedOnline(Workspace $workspace, string $name): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, 'ip_address' => '203.0.113.'.random_int(2, 250)]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return $machine;
}

/** @return array{0: Workspace, 1: Machine, 2: Machine, 3: Project, 4: ProjectNode, 5: ProjectNode} */
function movedProject(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $web = movedOnline($workspace, 'web');
    $db = movedOnline($workspace, 'db');
    $project = $workspace->projects()->create(['name' => 'Shop']);
    $environment = $project->production();
    $app = $project->nodes()->create(['machine_id' => $web->id, 'environment_id' => $environment->id, 'name' => 'shop', 'kind' => 'app']);
    $database = $project->nodes()->create(['machine_id' => $db->id, 'environment_id' => $environment->id, 'name' => 'shop-db', 'kind' => 'database', 'template' => 'postgres']);
    $project->links()->create(['app_node_id' => $app->id, 'resource_node_id' => $database->id]);
    foreach ([[$web, 2, 'web-key'], [$db, 3, 'db-key']] as [$machine, $host, $key]) {
        (new ProjectMachine)->forceFill(['project_id' => $project->id, 'machine_id' => $machine->id, 'host' => $host, 'listen_port' => ProjectMachine::FIRST_PORT, 'public_key' => $key, 'state' => 'up'])->save();
    }
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $web->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop',
        'state' => 'succeeded', 'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'env' => ['APP_ENV' => 'production']]], 'result' => [],
    ]);

    return [$workspace, $web, $db, $project, $app, $database];
}

/** @return list<array{machine: string, job: array<string, mixed>}> */
function movedCommands(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))
        ->map(fn (GatewayRequest $r) => ['machine' => explode('/', $r->url())[4] ?? '', 'job' => json_decode($r->body(), true)['command']['job']])
        ->values()
        ->all();
}

test('moving a machine takes it out of its old workspace’s projects, fixes, rollouts and maintenance', function () {
    [$workspace, $web, $db, $project, $app, $database] = movedProject();
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $web->id, 'state' => 'needs_approval', 'actions' => []]);
    $done = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $web->id, 'state' => 'fixed']);
    $rollout = PackageRollout::query()->create(['workspace_id' => $workspace->id, 'created_by' => $workspace->owner_id, 'name' => 'curl', 'packages' => [], 'mode' => 'latest']);
    $target = PackageRolloutMachine::query()->create(['package_rollout_id' => $rollout->id, 'machine_id' => $web->id]);
    $window = MaintenanceWindow::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $web->id, 'created_by' => $workspace->owner_id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'action' => 'reboot']);

    $this->actingAs($workspace->owner)->post('/workspaces', ['name' => 'Client B', 'machines' => [$web->id]])->assertRedirect();

    expect(ProjectNode::query()->find($app->id))->toBeNull()
        ->and(ProjectNode::query()->find($database->id))->not->toBeNull()
        ->and(ProjectLink::query()->exists())->toBeFalse()
        ->and(ProjectMachine::query()->pluck('machine_id')->all())->toBe([$db->id])
        ->and($fix->refresh()->state)->toBe('error')
        ->and($done->refresh()->state)->toBe('fixed')
        ->and($target->refresh()->state)->toBe('skipped')
        ->and($window->refresh()->state)->toBe('cancelled');

    $network = collect(movedCommands())->filter(fn ($c) => $c['job']['type'] === 'network');
    expect($network->firstWhere('machine', $web->uid)['job']['action'])->toBe('down')
        ->and($network->firstWhere('machine', $db->uid)['job']['peers'])->toBe([]);
});

test('the old workspace can’t act on a machine that moved through anything left pointing at it', function () {
    [$workspace, $web, , $project, $app] = movedProject();
    $member = User::factory()->create();
    $member->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $workspace->members()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $web->id, 'state' => 'needs_approval', 'actions' => [['type' => 'restart', 'service' => 'docker:shop', 'why' => 'a']]]);
    $rollout = PackageRollout::query()->create(['workspace_id' => $workspace->id, 'created_by' => $workspace->owner_id, 'name' => 'all', 'packages' => [], 'mode' => 'latest', 'state' => 'running']);
    $target = PackageRolloutMachine::query()->create(['package_rollout_id' => $rollout->id, 'machine_id' => $web->id]);
    $window = MaintenanceWindow::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $web->id, 'created_by' => $workspace->owner_id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(), 'action' => 'reboot']);

    $web->forceFill(['workspace_id' => Workspace::factory()->create()->id])->save();
    $before = DaemonJob::query()->count();

    $this->actingAs($member)->patch('https://'.$workspace->host()."/projects/{$project->uid}/nodes/{$app->id}/settings", ['set' => ['X' => 'y']])->assertNotFound();
    expect(fn () => app(FixRunner::class)->approve($fix->load('machine'), $member))->toThrow(RuntimeException::class, 'moved to another workspace');
    app(PackageRollouts::class)->advance($rollout);
    app(ScheduledMaintenance::class)->tick();
    app(ProjectNetwork::class)->sync($project, $workspace->owner);
    $link = ProjectLink::query()->sole();
    app(ProjectLinks::class)->connect($link, $workspace->owner);
    $diagnosing = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $web->id, 'state' => 'investigating']);
    app()->call([new DiagnoseFix($diagnosing->id), 'handle']);

    expect(DaemonJob::query()->where('machine_id', $web->id)->where('kind', '!=', 'network')->count())->toBe(1)
        ->and(DaemonJob::query()->where('machine_id', $web->id)->where('kind', 'network')->pluck('command')->pluck('job.action')->all())->toBe(['down'])
        ->and(DaemonJob::query()->count())->toBeGreaterThan($before)
        ->and($fix->refresh()->state)->toBe('needs_approval')
        ->and($link->refresh()->state)->toBe('failed')
        ->and($diagnosing->refresh()->state)->toBe('error')
        ->and($target->refresh()->state)->toBe('skipped')
        ->and($window->refresh()->state)->toBe('cancelled');
});
