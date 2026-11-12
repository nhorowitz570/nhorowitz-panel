<?php

use App\Enums\Plan;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Notifications\MonitorAlert;
use App\Support\Workflows\AgentSpec;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowSteps;
use App\Support\Workflows\WorkflowTemplates;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config()->set('services.github_app', [
        'id' => '12345', 'slug' => 'serveros', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh',
        'private_key' => base64_encode($pem),
    ]);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_write_token']),
        'api.github.com/users/serveros%5Bbot%5D' => Http::response(['login' => 'serveros[bot]', 'id' => 339015915, 'type' => 'Bot']),
        '*' => Http::response('ok'),
    ]);
    Notification::fake();
    $this->withoutDefer();
});

/** @return array{0: Workspace, 1: Machine} */
function agentWorkspace(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'acme', 'account_type' => 'Organization']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:agent1', 'name' => 'coding-agent-agent-1', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web', 'manager' => 'systemd', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return [$workspace, $machine];
}

/** @param  array<string, mixed>  $agent */
function agentFlow(Workspace $workspace, Machine $machine, array $agent = [], array $trigger = ['event' => 'manual']): Workflow
{
    $graph = WorkflowSteps::clean(['nodes' => [
        ['id' => 't', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'title' => '', 'description' => '', 'rules' => $trigger],
        ['id' => 'fix', 'kind' => 'agent', 'x' => 0, 'y' => 200, 'title' => '', 'description' => '', 'rules' => [
            'machine_id' => $machine->id,
            'service' => 'docker:agent1',
            'agent' => 'claude',
            'prompt' => 'Fix the failing build of {{service}}.',
            'repo' => 'acme/shop',
            'branch' => 'main',
            'pull_request' => true,
            'minutes' => 15,
            ...$agent,
        ]],
        ['id' => 'tell', 'kind' => 'alert', 'x' => 0, 'y' => 400, 'title' => '', 'description' => '', 'rules' => ['subject' => 'Agent: {{agent.pr}}', 'message' => '{{agent.summary}}', 'tone' => 'good']],
    ], 'edges' => [
        ['id' => 'e1', 'source' => 't', 'port' => 'out', 'target' => 'fix'],
        ['id' => 'e2', 'source' => 'fix', 'port' => 'out', 'target' => 'tell'],
    ]]);

    return $workspace->workflows()->create([
        'name' => 'Fix builds', 'graph' => $graph, 'enabled' => true, 'run_as' => $workspace->owner_id, 'trigger' => $trigger['event'],
    ]);
}

function agentJobDone(Machine $machine, DaemonJob $job, array $log, int $exit = 0, string $state = 'succeeded'): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => $state, 'log' => $log, 'result' => ['exit_code' => $exit]]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

test('an agent step runs in its container with the prompt, the repo and a read-only token, all handed in by name', function () {
    [$workspace, $machine] = agentWorkspace();
    $workflow = agentFlow($workspace, $machine);

    expect(WorkflowSteps::problems($workflow->graph, $workspace))->toBe([]);

    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', ['service' => 'shop'], $workspace->owner);
    $job = DaemonJob::query()->sole();
    $spec = $job->command['job'];

    expect($run->steps['fix']['state'])->toBe('running')
        ->and($run->steps['fix']['timeout'])->toBe(20)
        ->and($job->only(['kind', 'service', 'workflow_node']))->toBe(['kind' => 'service_exec', 'service' => 'docker:agent1', 'workflow_node' => 'fix'])
        ->and($job->command['timeout_secs'])->toBe(15 * 60 + 60)
        ->and($spec['timeout_secs'])->toBe(15 * 60)
        ->and($spec['command'])->toBe('exec sh -c "$SERVEROS_AGENT_SCRIPT" serveros-agent')
        ->and($spec['env']['SERVEROS_AGENT_SCRIPT'])->toBe(AgentSpec::script())
        ->and($spec['env']['SERVEROS_AGENT_KIND'])->toBe('claude')
        ->and($spec['env']['SERVEROS_AGENT_REPO'])->toBe('acme/shop')
        ->and($spec['env']['SERVEROS_AGENT_BRANCH'])->toBe('main')
        ->and($spec['env']['SERVEROS_AGENT_PR_BRANCH'])->toBe("serveros/agent-{$run->id}-fix")
        ->and($spec['env']['SERVEROS_AGENT_TITLE'])->toBe('Fix builds: changes from the agent')
        ->and($spec['env']['SERVEROS_GH_TOKEN'])->toBe('ghs_write_token')
        ->and($spec['env']['SERVEROS_AGENT_HANDOFF'])->toBe('1')
        ->and($spec['env']['SERVEROS_AGENT_RUN'])->toBe("Fix builds, run #{$run->id}")
        ->and($spec['env']['SERVEROS_AGENT_PROMPT'])->toStartWith('Fix the failing build of shop.')
        ->and($spec['env']['SERVEROS_AGENT_PROMPT'])->toContain('ServerOS pushes a branch and opens a pull request');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/access_tokens')
        && $r['permissions'] === ['contents' => 'read', 'metadata' => 'read']
        && $r['repositories'] === ['shop']);
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/access_tokens') && ($r['permissions']['contents'] ?? null) === 'write');

    expect(AgentSpec::script())->toContain('env -u GIT_TOKEN -u SERVEROS_GH_TOKEN -u GH_TOKEN')
        ->not->toContain('export GIT_TOKEN')
        ->not->toContain('push -q origin')
        ->toContain('core.hooksPath=/dev/null');
});

/** @param  list<array{path: string, mode: string, content: string|null}>  $files */
function agentChangeLog(array $files, string $base): array
{
    $payload = base64_encode(gzencode((string) json_encode(['base' => $base, 'files' => $files])));
    $chunks = str_split($payload, 40);

    return [
        '::serveros-model:: claude-haiku-5-5',
        '::serveros-stat:: 2 files changed, 1 insertion(+), 1 deletion(-)',
        '::serveros-summary::',
        'Pinned left-pad.',
        '::serveros-changes:: '.hash('sha256', $payload).' '.count($chunks),
        ...array_map(fn (string $c) => '::serveros-change:: '.$c, $chunks),
    ];
}

test('ServerOS opens the agent’s pull request itself, so no token that can write ever goes to the machine', function () {
    [$workspace, $machine] = agentWorkspace();
    $base = str_repeat('a', 40);
    Http::swap(new Factory);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/app/installations/42/access_tokens' => fn (Request $r) => Http::response(['token' => ($r['permissions']['contents'] ?? null) === 'write' ? 'ghs_write' : 'ghs_read']),
        "api.github.com/repos/acme/shop/git/commits/{$base}" => Http::response(['sha' => $base, 'tree' => ['sha' => 'basetree']]),
        'api.github.com/repos/acme/shop/git/blobs' => Http::response(['sha' => 'blob1'], 201),
        'api.github.com/repos/acme/shop/git/trees' => Http::response(['sha' => 'tree2'], 201),
        'api.github.com/repos/acme/shop/git/commits' => Http::response(['sha' => 'commit3'], 201),
        'api.github.com/repos/acme/shop/git/refs' => Http::response(['ref' => 'x'], 201),
        'api.github.com/repos/acme/shop/pulls' => Http::response(['html_url' => 'https://github.com/acme/shop/pull/9'], 201),
        '*' => Http::response('ok'),
    ]);
    $workflow = agentFlow($workspace, $machine, ['check' => 'npm test']);
    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);
    $job = DaemonJob::query()->sole();

    expect(json_encode($job->command))->not->toContain('ghs_write')
        ->and($job->command['job']['env']['SERVEROS_GH_TOKEN'])->toBe('ghs_read');

    agentJobDone($machine, $job, agentChangeLog([
        ['path' => 'package.json', 'mode' => '100644', 'content' => base64_encode("{}\n")],
        ['path' => 'old.txt', 'mode' => '100644', 'content' => null],
    ], $base));

    $run->refresh();
    expect($run->state)->toBe('succeeded')
        ->and($run->steps['fix']['message'])->toBe('Opened https://github.com/acme/shop/pull/9 (Claude Code, claude-haiku-5-5)')
        ->and($run->steps['fix']['pr'])->toBe('https://github.com/acme/shop/pull/9');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/blobs') && $r->hasHeader('Authorization', 'Bearer ghs_write') && $r['content'] === base64_encode("{}\n"));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees') && $r['base_tree'] === 'basetree'
        && $r['tree'] === [['path' => 'package.json', 'mode' => '100644', 'type' => 'blob', 'sha' => 'blob1'], ['path' => 'old.txt', 'mode' => '100644', 'type' => 'blob', 'sha' => null]]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/commits') && $r->method() === 'POST' && $r['parents'] === [$base] && str_starts_with($r['message'], 'Fix builds: changes from the agent'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/refs') && $r['ref'] === "refs/heads/serveros/agent-{$run->id}-fix" && $r['sha'] === 'commit3');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls') && $r['base'] === 'main' && $r['head'] === "serveros/agent-{$run->id}-fix"
        && str_contains($r['body'], 'Pinned left-pad.') && str_contains($r['body'], 'npm test') && str_contains($r['body'], '2 files changed'));
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'Agent: https://github.com/acme/shop/pull/9');
});

test('without the GitHub App on the repository, the agent pushes with the container’s own token as before', function () {
    [$workspace, $machine] = agentWorkspace();
    Http::swap(new Factory);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/app/installations/42/access_tokens' => Http::response(['message' => 'There is at least one repository that does not exist or is not accessible to the parent installation.'], 422),
        '*' => Http::response('ok'),
    ]);
    app(WorkflowEngine::class)->start(agentFlow($workspace, $machine), 'manual', 'Run by hand', [], $workspace->owner);
    $env = DaemonJob::query()->sole()->command['job']['env'];

    expect($env)->not->toHaveKey('SERVEROS_AGENT_HANDOFF')
        ->and($env)->not->toHaveKey('SERVEROS_GH_TOKEN')
        ->and($env['SERVEROS_AGENT_PR_BRANCH'])->toStartWith('serveros/agent-');
});

test('the agent’s summary can’t pass itself off as the change ServerOS sends back', function () {
    expect(substr_count(AgentSpec::script(), "head -c 6000 \"\$work/out.txt\" | grep -v '^::serveros-' | head -n 200"))->toBe(2);
});

test('a change that comes back damaged, or touches .git, opens no pull request', function () {
    [$workspace, $machine] = agentWorkspace();
    $workflow = agentFlow($workspace, $machine);
    app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);
    $job = DaemonJob::query()->sole();

    $log = agentChangeLog([['path' => '.git/hooks/pre-commit', 'mode' => '100755', 'content' => base64_encode('x')]], str_repeat('a', 40));
    agentJobDone($machine, $job, $log);

    $run = WorkflowRun::query()->sole();
    expect($run->state)->toBe('failed')
        ->and($run->steps['fix']['message'])->toContain('in a form ServerOS can’t read');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls') || str_contains($r->url(), '/git/'));

    $broken = agentChangeLog([['path' => 'a.txt', 'mode' => '100644', 'content' => base64_encode('x')]], str_repeat('a', 40));
    array_pop($broken);
    expect(fn () => AgentSpec::changes(new DaemonJob(['log' => $broken])))->toThrow(RuntimeException::class, 'whole');
});

test('the workspace’s AI key only goes to agents in workflows last saved by an owner or admin', function () {
    [$workspace, $machine] = agentWorkspace();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'anthropic', 'key' => 'sk-ant-theirs', 'hint' => '…eirs']);
    $member = User::factory()->create(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()]);
    $workspace->members()->attach($member, ['role' => 'member']);
    $workflow = agentFlow($workspace, $machine);
    $workflow->update(['run_as' => $member->id]);

    app(WorkflowEngine::class)->start($workflow->fresh(), 'manual', 'Run by hand', [], $member);
    $env = DaemonJob::query()->sole()->command['job']['env'];
    expect($env)->not->toHaveKey('ANTHROPIC_API_KEY')
        ->and($env['SERVEROS_AGENT_KEY_WITHHELD'])->toBe('1')
        ->and(AgentSpec::script())->toContain('only goes to workflows last saved by an owner or admin');

    $workflow->update(['run_as' => $workspace->owner_id]);
    $this->travel(5)->minutes();
    $machine->forceFill(['last_seen_at' => now()])->save();
    app(WorkflowEngine::class)->start($workflow->fresh(), 'manual', 'Run by hand', [], $workspace->owner);
    expect(DaemonJob::query()->latest('created_at')->get()->first(fn ($j) => isset($j->command['job']['env']['ANTHROPIC_API_KEY']))?->command['job']['env']['ANTHROPIC_API_KEY'])->toBe('sk-ant-theirs');
});

test('the model, setup and check go to the agent, and the prompt asks it to pass the check', function () {
    [$workspace, $machine] = agentWorkspace();
    $workflow = agentFlow($workspace, $machine, ['model' => 'haiku', 'setup' => 'npm ci', 'check' => 'npm test']);
    app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);
    $env = DaemonJob::query()->sole()->command['job']['env'];

    expect($env)->toMatchArray(['SERVEROS_AGENT_MODEL' => 'haiku', 'SERVEROS_AGENT_SETUP' => 'npm ci', 'SERVEROS_AGENT_CHECK' => 'npm test'])
        ->and($env['SERVEROS_AGENT_PROMPT'])->toContain('run `npm test` and make it pass');
});

test('a failed check keeps the agent’s summary but not the error line', function () {
    $job = new DaemonJob(['log' => ['Checking: npm test', '::serveros-summary::', 'Changed i to 0.', '::serveros-error:: The check failed after the agent\'s change, so no pull request was opened.']]);

    expect(AgentSpec::outcome($job))->toMatchArray([
        'summary' => 'Changed i to 0.',
        'error' => 'The check failed after the agent\'s change, so no pull request was opened.',
        'pr' => null,
        'model' => null,
    ]);
});

test('when the agent finishes, its pull request and summary go to the run and to later steps', function () {
    [$workspace, $machine] = agentWorkspace();
    $workflow = agentFlow($workspace, $machine);
    app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);
    $job = DaemonJob::query()->sole();

    agentJobDone($machine, $job, [
        'Cloning acme/shop at main',
        'Running Claude Code for up to 15 minutes',
        '::serveros-model:: claude-haiku-5-5',
        '::serveros-pr:: https://github.com/acme/shop/pull/7',
        '::serveros-summary::',
        'The build failed because composer.lock was out of date.',
        'I ran composer update --lock.',
    ]);

    $run = WorkflowRun::query()->sole();
    expect($run->state)->toBe('succeeded')
        ->and($run->steps['fix']['state'])->toBe('succeeded')
        ->and($run->steps['fix']['message'])->toBe('Opened https://github.com/acme/shop/pull/7 (Claude Code, claude-haiku-5-5)')
        ->and($run->steps['fix']['model'])->toBe('claude-haiku-5-5')
        ->and($run->steps['fix']['pr'])->toBe('https://github.com/acme/shop/pull/7')
        ->and($run->steps['fix']['summary'])->toBe("The build failed because composer.lock was out of date.\nI ran composer update --lock.")
        ->and(collect($run->logs)->pluck('message')->all())->toContain('The build failed because composer.lock was out of date.');

    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'Agent: https://github.com/acme/shop/pull/7');
});

test('an agent that fails leaves by failed with what its script said', function () {
    [$workspace, $machine] = agentWorkspace();
    $workflow = agentFlow($workspace, $machine);
    app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);
    $job = DaemonJob::query()->sole();

    agentJobDone($machine, $job, ['::serveros-error:: Set ANTHROPIC_API_KEY (or CLAUDE_CODE_OAUTH_TOKEN) on the agent\'s service.'], 1);

    $run = WorkflowRun::query()->sole();
    expect($run->state)->toBe('failed')
        ->and($run->steps['fix']['state'])->toBe('failed')
        ->and($run->steps['fix']['message'])->toBe('Set ANTHROPIC_API_KEY (or CLAUDE_CODE_OAUTH_TOKEN) on the agent\'s service.')
        ->and($run->steps)->not->toHaveKey('tell');
});

test('after a failed deploy, the agent works in the app’s own repository with the end of its build log', function () {
    [$workspace, $machine] = agentWorkspace();
    $deploy = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'failed',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop.git', 'commit' => str_repeat('a', 40)]],
        'log' => ['Step 4/9 : RUN npm ci', 'npm ERR! missing: left-pad@1.3.0'],
        'error' => ['message' => 'The build failed'],
    ]);
    $workflow = agentFlow($workspace, $machine, ['repo' => WorkflowSteps::FROM_TRIGGER], ['event' => 'deploy.finished', 'outcome' => 'failed']);

    expect(WorkflowSteps::problems($workflow->graph, $workspace))->toBe([]);

    app(WorkflowEngine::class)->start($workflow, 'deploy.finished', 'shop on web-1 failed to deploy', [
        'machine_id' => $machine->id, 'machine' => 'web-1', 'service' => 'shop', 'outcome' => 'failed', 'job_id' => $deploy->id,
    ]);
    $env = DaemonJob::query()->where('kind', 'service_exec')->sole()->command['job']['env'];

    expect($env['SERVEROS_AGENT_REPO'])->toBe('acme/shop')
        ->and($env['SERVEROS_AGENT_PROMPT'])->toContain('The deploy job the trigger was about ended failed: The build failed')
        ->and($env['SERVEROS_AGENT_PROMPT'])->toContain('npm ERR! missing: left-pad@1.3.0')
        ->and($env['SERVEROS_AGENT_PROMPT'])->toContain('- Machine: web-1');
});

test('an agent step says what it needs before it can be turned on', function () {
    [$workspace, $machine] = agentWorkspace();
    $problem = fn (array $rules, array $trigger = ['event' => 'manual']) => WorkflowSteps::problems(agentFlow($workspace, $machine, $rules, $trigger)->graph, $workspace)['fix'] ?? null;

    expect($problem([]))->toBeNull()
        ->and($problem(['service' => 'systemd:nginx.service']))->toBe('Choose the agent’s container, like one from the Coding agent template.')
        ->and($problem(['prompt' => '  ']))->toBe('Write what the agent should do.')
        ->and($problem(['agent' => 'gpt']))->toBe('Choose Claude Code or Codex.')
        ->and($problem(['repo' => 'not a repo']))->toBe('Enter the repository, as owner/name.')
        ->and($problem(['repo' => '', 'pull_request' => true]))->toBe('Choose a repository for the pull request.')
        ->and($problem(['repo' => '', 'pull_request' => false]))->toBeNull()
        ->and($problem(['repo' => WorkflowSteps::FROM_TRIGGER]))->toBe('Only a “deploy finishes” trigger has a repository to work in; enter one as owner/name.')
        ->and($problem(['minutes' => 90]))->toBe('Give it between 5 and 60 minutes.')
        ->and($problem(['model' => 'opus; rm -rf /']))->toBe('That isn’t a model name, like opus or gpt-5-codex.')
        ->and($problem(['repo' => '', 'pull_request' => false, 'check' => 'npm test']))->toBe('Setup and check commands run in the repository: choose one.');

    expect(WorkflowSteps::hasCommand(agentFlow($workspace, $machine)->graph))->toBeTrue();
});

test('the agent templates find the Coding agent container', function () {
    [$workspace, $machine] = agentWorkspace();

    foreach (['agent-fix-deploy', 'agent-issues'] as $key) {
        $graph = WorkflowTemplates::build($key, $workspace)['graph'];
        $agent = collect($graph['nodes'])->firstWhere('kind', 'agent');

        expect((array) $agent['rules'])->toMatchArray(['machine_id' => $machine->id, 'service' => 'docker:agent1', 'pull_request' => true]);
    }

    expect(WorkflowSteps::problems(WorkflowTemplates::build('agent-fix-deploy', $workspace)['graph'], $workspace))->toBe([])
        ->and(WorkflowSteps::problems(WorkflowTemplates::build('agent-issues', $workspace)['graph'], $workspace))->toBe([]);
});

test('a labelled GitHub issue sent to the webhook becomes the agent’s task, in the issue’s repository', function () {
    [$workspace, $machine] = agentWorkspace();
    $built = WorkflowTemplates::build('agent-issues', $workspace);
    $workflow = $workspace->workflows()->create(['name' => $built['name'], 'graph' => $built['graph'], 'enabled' => true, 'run_as' => $workspace->owner_id, 'trigger' => 'webhook']);

    $issue = [
        'action' => 'labeled',
        'label' => ['name' => 'agent'],
        'issue' => ['number' => 12, 'title' => 'Dark mode toggle forgets its setting', 'body' => 'It resets on reload.'],
        'repository' => ['full_name' => 'acme/shop', 'default_branch' => 'develop'],
    ];
    $token = $workflow->rotateWebhook();
    $workflow->save();
    $this->postJson('/api/hooks/workflows/'.$token, $issue)->assertStatus(202);

    $env = DaemonJob::query()->sole()->command['job']['env'];
    expect($env['SERVEROS_AGENT_REPO'])->toBe('acme/shop')
        ->and($env['SERVEROS_AGENT_BRANCH'])->toBe('develop')
        ->and($env['SERVEROS_AGENT_TITLE'])->toBe('Fix #12: Dark mode toggle forgets its setting')
        ->and($env['SERVEROS_AGENT_PROMPT'])->toContain("#12 Dark mode toggle forgets its setting\n\nIt resets on reload.");

    $this->travel(10)->minutes();
    $this->postJson('/api/hooks/workflows/'.$token, [...$issue, 'action' => 'opened'])->assertStatus(202);
    expect(WorkflowRun::query()->count())->toBe(2);
    expect(DaemonJob::query()->count())->toBe(1);
});
