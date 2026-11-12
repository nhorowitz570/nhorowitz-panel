<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowSteps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), '*' => Http::response('ok')]);
    Notification::fake();
    $this->withoutDefer();
});

/**
 * @return array{0: Workspace, 1: Machine}
 */
function flowWorkspace(?Plan $plan = Plan::Pro): array
{
    $factory = Workspace::factory();
    $workspace = ($plan ? $factory->onPlan($plan) : $factory->trialExpired())->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return [$workspace, $machine];
}

function flowUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/workflows'.$path;
}

/** @param  list<array{0: string, 1: string, 2: array<string, mixed>, 3?: string, 4?: string}>  $steps */
function flow(Workspace $workspace, array $steps, bool $enabled = true): Workflow
{
    $nodes = [];
    $edges = [];
    foreach ($steps as $i => $step) {
        [$id, $kind, $rules] = $step;
        $nodes[] = ['id' => $id, 'kind' => $kind, 'x' => 0, 'y' => $i * 200, 'title' => '', 'description' => '', 'rules' => $rules];
        if (isset($step[3])) {
            $edges[] = ['id' => "e{$i}", 'source' => $step[3], 'port' => $step[4] ?? 'out', 'target' => $id];
        }
    }
    $graph = WorkflowSteps::clean(['nodes' => $nodes, 'edges' => $edges]);

    return $workspace->workflows()->create([
        'name' => 'Test flow', 'graph' => $graph, 'enabled' => $enabled, 'run_as' => $workspace->owner_id,
        'trigger' => $steps[0][2]['event'] ?? 'manual',
    ]);
}

function finishJob(Machine $machine, DaemonJob $job, string $state = 'succeeded', array $extra = []): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => $state, 'log' => ['line one'], ...$extra]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

test('templates make a workflow that is off until its steps are filled in', function () {
    [$workspace] = flowWorkspace();

    $this->actingAs($workspace->owner)->post(flowUrl($workspace), ['template' => 'ci-deploy'])->assertRedirect();
    $workflow = Workflow::query()->sole();

    expect($workflow->enabled)->toBeFalse()
        ->and($workflow->trigger)->toBe('webhook')
        ->and($workflow->webhookUrl())->toStartWith('http')
        ->and(collect($workflow->graph['nodes'])->pluck('kind')->all())->toBe(['trigger', 'branch', 'redeploy', 'check_monitor', 'alert', 'alert', 'alert']);

    $this->actingAs($workspace->owner)->get(flowUrl($workspace, "/{$workflow->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/WorkflowEditor')
            ->where('workflow.name', 'Deploy when CI passes')
            ->where('problems', fn ($p) => count($p) >= 1)
            ->has('options.machines.0.services', 1));

    $this->actingAs($workspace->owner)->put(flowUrl($workspace, "/{$workflow->id}"), [
        'name' => 'CI', 'enabled' => true, 'graph' => $workflow->graph,
    ])->assertSessionHasErrors('enabled');
});

test('a run restarts a service, waits for the server, and follows the outcome', function () {
    [$workspace, $machine] = flowWorkspace();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['r', 'restart', ['machine_id' => $machine->id, 'service' => 'docker:aaa'], 't'],
        ['ok', 'alert', ['subject' => 'Restarted {{workflow}}', 'tone' => 'good'], 'r'],
        ['bad', 'alert', ['subject' => 'Restart failed'], 'r', 'failed'],
    ]);

    $this->actingAs($workspace->owner)->post(flowUrl($workspace, "/{$workflow->id}/run"))->assertRedirect();
    $run = WorkflowRun::query()->sole();
    $job = DaemonJob::query()->sole();

    expect($run->state)->toBe('running')
        ->and($run->steps['t']['state'])->toBe('succeeded')
        ->and($run->steps['r']['state'])->toBe('running')
        ->and($job->only(['kind', 'service', 'workflow_run_id', 'workflow_node']))->toBe(['kind' => 'service_action', 'service' => 'docker:aaa', 'workflow_run_id' => $run->id, 'workflow_node' => 'r']);

    finishJob($machine, $job);

    $run->refresh();
    expect($run->state)->toBe('succeeded')
        ->and($run->steps['ok']['state'])->toBe('succeeded')
        ->and($run->steps)->not->toHaveKey('bad');
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'Restarted Test flow');

    expect(Activity::query()->pluck('action')->all())->toContain('workflow.run', 'service.restart');
});

test('a failure goes down the failed path, or fails the run when there is none', function () {
    [$workspace, $machine] = flowWorkspace();
    $handled = flow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['r', 'restart', ['machine_id' => $machine->id, 'service' => 'docker:aaa'], 't'],
        ['bad', 'alert', ['subject' => 'Restart failed'], 'r', 'failed'],
    ]);
    $run = app(WorkflowEngine::class)->start($handled, 'manual', 'test');
    finishJob($machine, DaemonJob::query()->latest()->first(), 'failed', ['error' => ['message' => 'container not found']]);
    $run->refresh();
    expect($run->state)->toBe('succeeded')
        ->and($run->steps['r']['message'])->toBe('container not found')
        ->and($run->steps['bad']['state'])->toBe('succeeded');

    Notification::assertSentTimes(MonitorAlert::class, 1);

    $unhandled = flow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['r', 'restart', ['machine_id' => $machine->id, 'service' => 'docker:gone'], 't'],
    ]);
    $run = app(WorkflowEngine::class)->start($unhandled, 'manual', 'test');
    expect($run->state)->toBe('failed')
        ->and($run->steps['r']['message'])->toContain('isn’t on web-1');
    Notification::assertSentTimes(MonitorAlert::class, 1);

    app(WorkflowEngine::class)->start($unhandled, 'schedule', 'On schedule', limited: false);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'Test flow failed');
});

test('waits are picked up by the tick, and branches choose a side', function () {
    [$workspace] = flowWorkspace();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'webhook']],
        ['w', 'wait', ['minutes' => 2], 't'],
        ['b', 'branch', ['condition' => 'trigger_field', 'field' => 'ref', 'value' => 'refs/heads/main'], 'w'],
        ['yes', 'http', ['method' => 'POST', 'url' => 'https://example.com/hook'], 'b', 'true'],
        ['no', 'alert', ['subject' => 'Not main'], 'b', 'false'],
    ]);

    $run = app(WorkflowEngine::class)->start($workflow, 'webhook', 'push', ['ref' => 'refs/heads/main']);
    expect($run->steps['w']['state'])->toBe('waiting')->and($run->wake_at)->not->toBeNull();

    app(WorkflowEngine::class)->tick();
    expect($run->fresh()->steps['w']['state'])->toBe('waiting');

    $this->travel(3)->minutes();
    app(WorkflowEngine::class)->tick();
    $run->refresh();
    expect($run->state)->toBe('succeeded')
        ->and($run->steps['b']['port'])->toBe('true')
        ->and($run->steps['yes']['message'])->toContain('answered 200')
        ->and($run->steps)->not->toHaveKey('no');
});

test('the webhook starts an enabled workflow with its JSON as data', function () {
    [$workspace] = flowWorkspace();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'webhook']],
        ['a', 'alert', ['subject' => 'Pushed {{data.ref}}'], 't'],
    ]);
    $token = $workflow->rotateWebhook();
    $workflow->save();

    $this->postJson('/api/hooks/workflows/'.$token, ['ref' => 'refs/heads/main'])->assertStatus(202);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'Pushed refs/heads/main');

    $this->postJson('/api/hooks/workflows/wh_'.str_repeat('x', 40))->assertNotFound();
    $workflow->update(['enabled' => false]);
    $this->postJson('/api/hooks/workflows/'.$token)->assertStatus(409);
});

test('trigger data reaches a command as variables, never as shell text', function () {
    [$workspace, $machine] = flowWorkspace();
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.36'])->save();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'webhook']],
        ['c', 'command', ['machine_id' => $machine->id, 'service' => 'docker:aaa', 'command' => "echo {{data.ref}} \"{{trigger}}\" '{{data.ref}}' # {{data.x}}"], 't'],
    ]);
    $token = $workflow->rotateWebhook();
    $workflow->save();

    $this->postJson('/api/hooks/workflows/'.$token, ['ref' => 'main; touch /tmp/pwned $(id) `id`', 'x' => 'y'])->assertStatus(202);

    $spec = DaemonJob::query()->where('kind', 'service_exec')->sole()->command['job'];
    expect($spec['command'])->toBe("echo \${SERVEROS_V1} \"\${SERVEROS_V2}\" ''\"\${SERVEROS_V1}\"'' # \${SERVEROS_V3}")
        ->and($spec['env']['SERVEROS_V1'])->toBe('main; touch /tmp/pwned $(id) `id`')
        ->and($spec['env']['SERVEROS_V3'])->toBe('y')
        ->and($spec['env'])->toHaveKey('SERVEROS_V2');

    $this->travel(10)->minutes();
    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.30', 'last_seen_at' => now()])->save();
    $this->postJson('/api/hooks/workflows/'.$token, ['ref' => 'main'])->assertStatus(202);
    expect(DaemonJob::query()->where('kind', 'service_exec')->count())->toBe(1)
        ->and(WorkflowRun::query()->latest('id')->first()->steps['c']['message'])->toContain('Update the ServerOS daemon on web-1');
});

test('events start the workflows waiting on them, once per cooldown', function () {
    [$workspace, $machine] = flowWorkspace();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'service.down']],
        ['s', 'start', ['machine_id' => '@trigger', 'service' => '@trigger'], 't'],
    ]);
    flow($workspace, [['t', 'trigger', ['event' => 'monitor.down']], ['a', 'alert', ['subject' => 'x'], 't']]);

    Activity::system($workspace, 'service.down', 'shop', $machine, ['key' => 'docker:aaa']);

    $run = WorkflowRun::query()->sole();
    expect($run->workflow_id)->toBe($workflow->id)
        ->and($run->trigger['summary'])->toBe('shop stopped')
        ->and(DaemonJob::query()->sole()->command['job'])->toBe(['type' => 'service_action', 'service' => 'docker:aaa', 'action' => 'start']);

    Activity::system($workspace, 'service.down', 'shop', $machine, ['key' => 'docker:aaa']);
    expect(WorkflowRun::query()->count())->toBe(1);
});

test('scheduled workflows run when due and move to the next time', function () {
    [$workspace, $machine] = flowWorkspace();
    $this->travelTo(now()->setTime(2, 59));
    $this->actingAs($workspace->owner)->post(flowUrl($workspace), ['template' => 'nightly-backup']);
    $workflow = Workflow::query()->sole();
    $this->actingAs($workspace->owner)->put(flowUrl($workspace, "/{$workflow->id}"), ['name' => 'Nightly', 'enabled' => true, 'graph' => $workflow->graph])
        ->assertSessionHasNoErrors();

    expect($workflow->fresh()->next_run_at->format('H:i'))->toBe('03:00');

    $this->artisan('workflows:tick');
    expect(WorkflowRun::query()->count())->toBe(0);

    $this->travel(2)->minutes();
    $this->artisan('workflows:tick');
    expect(WorkflowRun::query()->count())->toBe(1)
        ->and($workflow->fresh()->next_run_at->isTomorrow())->toBeTrue();
});

test('only people who manage machines change or run workflows; commands need two-factor', function () {
    [$workspace, $machine] = flowWorkspace();
    $workflow = flow($workspace, [['t', 'trigger', ['event' => 'manual']], ['a', 'alert', ['subject' => 'x'], 't']]);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->get(flowUrl($workspace, "/{$workflow->id}"))->assertOk();
    $this->actingAs($viewer)->post(flowUrl($workspace, "/{$workflow->id}/run"))->assertForbidden();
    $this->actingAs($viewer)->put(flowUrl($workspace, "/{$workflow->id}"), ['name' => 'x', 'enabled' => false, 'graph' => $workflow->graph])->assertForbidden();

    $withCommand = ['nodes' => [...$workflow->graph['nodes'], ['id' => 'c', 'kind' => 'command', 'x' => 0, 'y' => 400, 'title' => '', 'description' => '', 'rules' => ['machine_id' => $machine->id, 'service' => 'docker:aaa', 'command' => 'php artisan migrate']]], 'edges' => $workflow->graph['edges']];
    $this->actingAs($workspace->owner)->put(flowUrl($workspace, "/{$workflow->id}"), ['name' => 'x', 'enabled' => false, 'graph' => $withCommand])
        ->assertSessionHasErrors('graph');

    $other = flowWorkspace()[0];
    $this->actingAs($other->owner)->get(flowUrl($other, "/{$workflow->id}"))->assertNotFound();
});

test('an HTTP step’s URL and body are stored encrypted and hidden from people who can’t manage machines', function () {
    [$workspace] = flowWorkspace();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['h', 'http', ['method' => 'POST', 'url' => 'https://hooks.slack.com/services/T1/B2/SECRET', 'body' => '{"routing_key":"RK123"}'], 't'],
    ]);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $raw = (string) DB::table('workflows')->where('id', $workflow->id)->value('graph');
    expect($raw)->not->toContain('SECRET')->not->toContain('RK123')
        ->and($workflow->fresh()->graph['nodes'][1]['rules']['url'])->toBe('https://hooks.slack.com/services/T1/B2/SECRET');

    $text = mcpCall($workspace, mcpKey($workspace, $viewer), 'get_workflow', ['workflow' => $workflow->id])['text'];
    expect($text)->not->toContain('SECRET')->not->toContain('RK123')
        ->and(mcpCall($workspace, mcpKey($workspace), 'get_workflow', ['workflow' => $workflow->id])['text'])->toContain('RK123');

    app(WorkflowEngine::class)->start($workflow->fresh(), 'manual', 'Run by hand', [], $workspace->owner);
    $run = WorkflowRun::query()->sole();
    expect((string) DB::table('workflow_runs')->where('id', $run->id)->value('graph'))->not->toContain('SECRET');
    Http::assertSent(fn ($r) => $r->url() === 'https://hooks.slack.com/services/T1/B2/SECRET' && $r->body() === '{"routing_key":"RK123"}');

    $this->actingAs($viewer)->get(flowUrl($workspace, "/{$workflow->id}?run={$run->id}"))
        ->assertOk()
        ->assertDontSee('SECRET')->assertDontSee('RK123')
        ->assertInertia(fn (Assert $page) => $page->where('workflow.graph.nodes.1.rules.url', 'https://hooks.slack.com/…')->where('run.graph.nodes.1.rules.body', '[hidden]'));

    $this->actingAs($workspace->owner)->get(flowUrl($workspace, "/{$workflow->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('workflow.graph.nodes.1.rules.url', 'https://hooks.slack.com/services/T1/B2/SECRET'));

});

test('existing HTTP step secrets are encrypted by the migration, and put back by its rollback', function () {
    [$workspace] = flowWorkspace();
    $workflow = flow($workspace, [['t', 'trigger', ['event' => 'manual']], ['h', 'http', ['method' => 'POST', 'url' => 'https://example.com/hook', 'body' => ''], 't']]);
    $plain = json_encode(['nodes' => [['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']], ['id' => 'h', 'kind' => 'http', 'rules' => ['method' => 'POST', 'url' => 'https://example.com/hook?key=abc', 'body' => '{"k":"v"}']]], 'edges' => []]);
    DB::table('workflows')->where('id', $workflow->id)->update(['graph' => $plain]);
    $migration = require database_path('migrations/2026_12_11_000000_encrypt_workflow_http_secrets.php');

    $migration->up();
    $migration->up();
    $raw = (string) DB::table('workflows')->where('id', $workflow->id)->value('graph');
    expect($raw)->not->toContain('key=abc')->not->toContain('"k"')
        ->and($workflow->fresh()->graph['nodes'][1]['rules'])->toBe(['method' => 'POST', 'url' => 'https://example.com/hook?key=abc', 'body' => '{"k":"v"}']);

    $migration->down();
    expect(json_decode((string) DB::table('workflows')->where('id', $workflow->id)->value('graph'), true))->toBe(json_decode($plain, true));
});

test('workflows need Pro', function () {
    [$workspace] = flowWorkspace(Plan::Essential);

    $this->actingAs($workspace->owner)->post(flowUrl($workspace), ['template' => 'blank'])->assertSessionHasErrors('template');
});

test('a step is the workflow’s doing: marked as such, without the IP of whoever pressed Run, even staff viewing as them', function () {
    [$workspace, $machine] = flowWorkspace();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['r', 'restart', ['machine_id' => $machine->id, 'service' => 'docker:aaa'], 't'],
    ]);
    $boss = User::factory()->admin()->create(['name' => 'Boss']);

    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Their restart fails']);
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) Chrome/155.0')->post(flowUrl($workspace, "/{$workflow->id}/run"))->assertRedirect();

    $run = Activity::query()->where('action', 'workflow.run')->sole();
    expect($run->impersonation_session_id)->not->toBeNull();

    $step = Activity::query()->where('action', 'service.restart')->sole();
    expect($step->user_id)->toBe($workspace->owner_id)
        ->and($step->impersonation_session_id)->toBeNull()
        ->and($step->source)->toBe('workflow')
        ->and($step->meta)->toMatchArray(['via' => 'workflow', 'workflow' => 'Test flow', 'run' => WorkflowRun::query()->sole()->id])
        ->and($step->ip)->toBeNull()
        ->and($step->user_agent)->toBeNull();
});

test('stopping a run is recorded, and names ServerOS Support when staff did it', function () {
    [$workspace, $machine] = flowWorkspace();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['r', 'restart', ['machine_id' => $machine->id, 'service' => 'docker:aaa'], 't'],
    ]);
    $this->actingAs($workspace->owner)->post(flowUrl($workspace, "/{$workflow->id}/run"));
    $run = WorkflowRun::query()->sole();

    $boss = User::factory()->admin()->create(['name' => 'Boss']);
    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Stuck run']);
    $this->post(flowUrl($workspace, "/{$workflow->id}/runs/{$run->id}/cancel"))->assertRedirect();

    $entry = Activity::query()->where('action', 'workflow.cancel')->sole();
    expect($entry->impersonation_session_id)->not->toBeNull()
        ->and($entry->meta)->toMatchArray(['run' => $run->id])
        ->and(collect($run->refresh()->logs)->last()['message'])->toBe('Cancelled by ServerOS Support.');

    $this->post(flowUrl($workspace, "/{$workflow->id}/runs/{$run->id}/cancel"))->assertRedirect();
    expect(Activity::query()->where('action', 'workflow.cancel')->count())->toBe(1);
});

test('staff viewing as someone can run a workflow but not save one, which would go on running as them', function () {
    [$workspace, $machine] = flowWorkspace();
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['r', 'restart', ['machine_id' => $machine->id, 'service' => 'docker:aaa'], 't'],
    ]);
    $boss = User::factory()->admin()->create(['name' => 'Boss']);
    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Their restart fails']);

    $this->postJson(flowUrl($workspace), ['template' => 'blank'])
        ->assertForbidden()
        ->assertJsonPath('message', 'You’re viewing as this person, so saving workflows is off limits. They’d go on running as them.');
    $this->putJson(flowUrl($workspace, "/{$workflow->id}"), ['name' => 'Renamed'])->assertForbidden();

    expect($workspace->workflows()->count())->toBe(1)
        ->and($workflow->fresh()->name)->toBe('Test flow');
});

test('a command step’s output reaches only people who manage machines', function () {
    [$workspace, $machine] = flowWorkspace();
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $workflow = flow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['c', 'command', ['machine_id' => (string) $machine->id, 'service' => 'docker:aaa', 'command' => 'printenv'], 't'],
    ]);
    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'By hand', [], $workspace->owner, limited: false);
    finishJob($machine, DaemonJob::query()->where('kind', 'service_exec')->sole(), 'succeeded', [
        'log' => ['$ printenv', 'DB_PASSWORD=hunter2'], 'result' => ['exit_code' => 0, 'stdout' => 'DB_PASSWORD=hunter2'],
    ]);
    expect($run->fresh()->steps['c']['message'])->toBe('Command finished: DB_PASSWORD=hunter2');

    $this->actingAs($viewer)->get(flowUrl($workspace, "/{$workflow->id}?run={$run->id}"))
        ->assertDontSee('hunter2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('run.steps.c.state', 'succeeded')
            ->where('run.steps.c.message', 'Command finished'));

    $this->actingAs($workspace->owner)->get(flowUrl($workspace, "/{$workflow->id}?run={$run->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('run.steps.c.message', 'Command finished: DB_PASSWORD=hunter2'));
});
