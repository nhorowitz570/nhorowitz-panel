<?php

use App\Enums\Feature;
use App\Enums\Limit;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\Workspace;
use App\Models\WorkspaceDomain;
use App\Support\Domains\CustomDomains;
use App\Support\Domains\DomainResolver;
use App\Support\Entitlements\Entitlements;
use App\Support\Extensions\Extensions;
use App\Support\Monitors\MonitorSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function fixesSelfHosted(): void
{
    config(['serveros.edition' => 'self-hosted', 'services.cloudflare.token' => null, 'services.cloudflare.zone_id' => null]);
    app()->forgetInstance(Entitlements::class);
    app(Extensions::class)->setUpgradeLink(null);
    DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
}

function fixesUrl(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

/** @param  array<string, list<string>>  $addresses */
function fixesResolver(array $addresses, array $cnames = []): void
{
    app()->instance(DomainResolver::class, new class($addresses, $cnames) extends DomainResolver
    {
        public function __construct(private array $addresses, private array $cnames) {}

        public function addresses(string $domain): array
        {
            return $this->addresses[$domain] ?? [];
        }

        public function cname(string $domain): ?string
        {
            return $this->cnames[$domain] ?? null;
        }
    });
}

test('a package’s tool guard refuses a tool through MCP and the REST API alike, and leaves the tool listed', function () {
    $workspace = Workspace::factory()->create();
    app(Extensions::class)->addToolGuard(fn (Tool $tool, McpContext $context) => $tool->name() === 'update_workspace' ? 'Not today.' : null);

    $call = mcpCall($workspace, mcpKey($workspace), 'update_workspace', ['name' => 'Renamed']);
    expect($call['error'])->toBeTrue()->and($call['text'])->toContain('Not today.')
        ->and(collect(mcpRequest($workspace, mcpKey($workspace), 'tools/list')->json('result.tools'))->pluck('name'))->toContain('update_workspace');

    [, $key] = McpToken::issueKey($workspace, $workspace->owner, 'CI', Scopes::ALL, McpToken::API);
    $this->withHeader('Authorization', "Bearer {$key}")->postJson('/api/v1/tools/update_workspace', ['name' => 'Renamed'])
        ->assertForbidden()
        ->assertJsonPath('error.message', 'Not today.');
    expect($workspace->fresh()?->name)->not->toBe('Renamed');
});

test('a package’s head tags go in the page’s head, and there are none without one', function () {
    $this->withoutVite();
    $login = 'https://'.config('app.domain').'/login';
    $before = (string) $this->get($login)->getContent();

    app(Extensions::class)->addHeadTag(fn (array $page) => $page['component'] === 'auth/Login' ? '<meta name="x-test" content="yes">' : null);
    $html = (string) $this->get($login)->getContent();

    expect($html)->toContain('<meta name="x-test" content="yes">')
        ->and(strpos($html, 'x-test'))->toBeLessThan(strpos($html, '</head>'))
        ->and($before)->not->toContain('x-test');
});

test('a self-hosted install without billing has no billing permission or billing wording in roles', function () {
    $cloud = [WorkspaceRole::Owner->description(), WorkspaceRole::Admin->description(), WorkspacePermission::offered()];
    expect($cloud[0])->toBe('Full access, including billing.')
        ->and($cloud[2])->toBe(WorkspacePermission::cases());

    fixesSelfHosted();
    $workspace = Workspace::factory()->create();

    expect(WorkspaceRole::Owner->description())->toBe('Full access, including deleting the workspace.')
        ->and(WorkspaceRole::Admin->description())->toBe('Everything except deleting the workspace.')
        ->and(WorkspacePermission::offered())->not->toContain(WorkspacePermission::ManageBilling);

    $this->actingAs($workspace->owner)->get(fixesUrl($workspace, '/settings/members'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions', fn ($permissions) => collect($permissions)->pluck('value')->doesntContain('manage-billing'))
            ->where('roles.0.permissions', fn ($permissions) => collect($permissions)->doesntContain('manage-billing'))
            ->where('roles.0.description', 'Full access, including deleting the workspace.'));

    $roles = mcpCall($workspace, mcpKey($workspace), 'list_roles')['data'];
    expect(json_encode($roles))->not->toContain('manage-billing')->not->toContain('leaves Business');
});

test('locked paid features on a self-hosted install say they need a license, not a plan', function () {
    fixesSelfHosted();
    $workspace = Workspace::factory()->create();

    expect($workspace->hasFeature(Feature::CustomDomains))->toBeFalse();
    $this->actingAs($workspace->owner)->post(fixesUrl($workspace, '/settings/domains'), ['purpose' => 'dashboard', 'hostname' => 'ops.acme.test'])
        ->assertSessionHasErrors(['hostname' => 'Your own domains need a ServerOS license.']);
    $this->actingAs($workspace->owner)->put(fixesUrl($workspace, '/settings/status-page'), ['enabled' => true, 'public' => true, 'slug' => 'acme-fleet', 'title' => 'Acme', 'description' => null, 'notice' => null, 'notice_kind' => null, 'show_uptime' => true, 'machines' => null])
        ->assertSessionHasErrors(['enabled' => 'One status page for every machine needs a ServerOS license.']);
    $this->actingAs($workspace->owner)->post(fixesUrl($workspace, '/settings/roles'), ['name' => 'Ops', 'permissions' => ['manage-machines']])
        ->assertForbidden();

    $text = json_encode(mcpCall($workspace, mcpKey($workspace), 'get_fleet_status_page')['data']);
    expect($text)->toContain('needs a ServerOS license')->not->toContain('Business');
});

test('the machine and monitor limits on a self-hosted install say what applies there', function () {
    fixesSelfHosted();
    config(['serveros.self_hosted.machines' => 1, 'serveros.self_hosted.monitors' => 0, 'daemon.install_ready' => true]);
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->create();

    $this->actingAs($workspace->owner)->postJson(fixesUrl($workspace, '/machines/connect'), [])
        ->assertStatus(409)
        ->assertJsonPath('error', 'This install allows 1 machines per workspace. Remove one, or ask whoever runs this install to raise SERVEROS_SELF_HOSTED_MACHINES.');
    $call = mcpCall($workspace, mcpKey($workspace), 'add_machine');
    expect($call['text'])->toContain('SERVEROS_SELF_HOSTED_MACHINES')->not->toContain('plan');
    expect(MonitorSettings::limitReached($workspace))->toContain('This install allows 0 monitors');
});

test('a licensed self-hosted workspace connects its own domain without Cloudflare, checked by DNS, and Caddy may get its certificate', function () {
    fixesSelfHosted();
    Http::fake();
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    app()->instance(Entitlements::class, new class(app(Entitlements::class)) implements Entitlements
    {
        public function __construct(private Entitlements $free) {}

        public function active(Workspace $workspace): bool
        {
            return true;
        }

        public function has(Workspace $workspace, Feature $feature): bool
        {
            return $feature === Feature::CustomDomains || $this->free->has($workspace, $feature);
        }

        public function limit(Workspace $workspace, Limit $limit): ?int
        {
            return $this->free->limit($workspace, $limit);
        }
    });
    $panel = (string) config('app.domain');
    fixesResolver([$panel => ['198.51.100.7']]);

    $url = fixesUrl($workspace, '/settings/domains');
    $this->actingAs($workspace->owner)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('ready', true)
        ->where('direct', true)
        ->where('cnameTarget', $panel));

    $this->actingAs($workspace->owner)->post($url, ['purpose' => 'dashboard', 'hostname' => 'ops.acme.test'])->assertSessionHasNoErrors();
    Http::assertNothingSent();
    $domain = WorkspaceDomain::query()->sole();
    expect($domain->state)->toBe('pending')
        ->and($domain->cloudflare_id)->toBeNull()
        ->and($domain->verification['records'])->toBe([['type' => 'CNAME', 'name' => 'ops.acme.test', 'value' => $panel]])
        ->and($domain->error)->toBe('It doesn’t point anywhere yet.');

    $ask = fn (string $host) => $this->call('GET', '/api/internal/tls-check', ['domain' => $host], [], [], ['REMOTE_ADDR' => '127.0.0.1'])->status();
    expect($ask('ops.acme.test'))->toBe(200)->and($ask('other.acme.test'))->toBe(404);

    fixesResolver([$panel => ['198.51.100.7'], 'ops.acme.test' => ['203.0.113.1']]);
    $this->actingAs($workspace->owner)->post(fixesUrl($workspace, "/settings/domains/{$domain->id}/check"))->assertRedirect();
    expect($domain->fresh())->state->toBe('pending')->error->toBe('It points at 203.0.113.1, not at this panel (198.51.100.7).');

    fixesResolver([$panel => ['198.51.100.7'], 'ops.acme.test' => ['198.51.100.7']]);
    $this->travel(2)->minutes();
    $this->artisan('domains:check')->assertSuccessful();
    expect($domain->fresh())->state->toBe('active')->error->toBeNull()->verification->toBeNull()
        ->and(CustomDomains::resolve('ops.acme.test'))->toMatchArray(['workspace' => $workspace->id, 'purpose' => WorkspaceDomain::DASHBOARD])
        ->and($workspace->fresh()?->host())->toBe('ops.acme.test');
});

test('serveros.com’s domains page is as it was', function () {
    config(['services.cloudflare.token' => 'cf-token', 'services.cloudflare.zone_id' => 'zone', 'services.cloudflare.cname_target' => 'customers.serveros.com']);
    $workspace = Workspace::factory()->create();

    expect(CustomDomains::direct())->toBeFalse()->and(CustomDomains::target())->toBe('customers.serveros.com');
    $this->actingAs($workspace->owner)->get(fixesUrl($workspace, '/settings/domains'))->assertInertia(fn (Assert $page) => $page
        ->where('ready', true)
        ->missing('direct')
        ->where('cnameTarget', 'customers.serveros.com'));
});

test('making another workspace on a self-hosted install mentions no trial or plan', function () {
    $workspace = Workspace::factory()->create();
    $this->actingAs($workspace->owner)->getJson('/workspaces/movable-machines')->assertOk()->assertJsonMissingPath('selfHosted');

    fixesSelfHosted();
    $this->actingAs($workspace->owner)->getJson('/workspaces/movable-machines')->assertOk()->assertJsonPath('selfHosted', true)->assertJsonPath('trial', false);
});
