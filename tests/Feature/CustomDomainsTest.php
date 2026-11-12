<?php

use App\Enums\Plan;
use App\Http\Middleware\BindCustomDomainSession;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDomain;
use App\Models\WorkspaceStatusPage;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Domains\CustomDomains;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\SignInHooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('services.cloudflare.token', 'cf-token');
    config()->set('services.cloudflare.zone_id', 'zone-1');
    config()->set('services.cloudflare.cname_target', 'customers.serveros.com');
    CustomDomains::forget();
    cfLive([]);

    Http::fake(['api.cloudflare.com/*' => function ($request) {
        $hostname = $request->method() === 'POST' ? $request['hostname'] : str_replace('cf-', '', basename($request->url()));
        if ($request->method() === 'DELETE') {
            return Http::response(['success' => true, 'errors' => [], 'result' => ['id' => 'cf-'.$hostname]]);
        }
        $live = in_array($hostname, cfLive(), true);

        return Http::response(cfHostname($hostname, $live ? 'active' : 'pending', $live ? 'active' : 'pending_validation'));
    }]);
});

/**
 * @param  list<string>|null  $set
 * @return list<string>
 */
function cfLive(?array $set = null): array
{
    static $live = [];
    if ($set !== null) {
        $live = $set;
    }

    return $live;
}

function cfHostname(string $hostname, string $status = 'pending', string $ssl = 'pending_validation'): array
{
    return ['success' => true, 'errors' => [], 'result' => [
        'id' => 'cf-'.$hostname,
        'hostname' => $hostname,
        'status' => $status,
        'ssl' => ['status' => $ssl, 'validation_errors' => []],
        'ownership_verification' => ['type' => 'txt', 'name' => '_cf-custom-hostname.'.$hostname, 'value' => 'abc-123'],
        'verification_errors' => $status === 'active' ? [] : ['custom hostname does not CNAME to this zone.'],
    ]];
}

/** @return array{0: Workspace, 1: string} */
function domainWorkspace(Plan $plan = Plan::Business, string $slug = 'acme'): array
{
    $workspace = Workspace::factory()->onPlan($plan)->create(['slug' => $slug]);

    return [$workspace, 'https://'.$workspace->subdomain().'/settings/domains'];
}

function liveDomain(Workspace $workspace, string $hostname = 'ops.acme.test', string $purpose = WorkspaceDomain::DASHBOARD): WorkspaceDomain
{
    $domains = app(CustomDomains::class);
    $domain = $domains->connect($workspace, $purpose, $hostname);
    cfLive([...cfLive(), $hostname]);
    $domains->refresh($domain);

    return $domain->fresh();
}

test('connecting a domain asks Cloudflare and shows the records to add', function () {
    [$workspace, $url] = domainWorkspace();
    $this->actingAs($workspace->owner)->post($url, ['purpose' => 'dashboard', 'hostname' => 'https://OPS.acme.test/'])->assertRedirect();

    $domain = WorkspaceDomain::query()->sole();
    expect($domain->hostname)->toBe('ops.acme.test')
        ->and($domain->state)->toBe('pending')
        ->and($domain->verification['records'])->toBe([
            ['type' => 'CNAME', 'name' => 'ops.acme.test', 'value' => 'customers.serveros.com'],
            ['type' => 'TXT', 'name' => '_cf-custom-hostname.ops.acme.test', 'value' => 'abc-123'],
        ]);
    Http::assertSent(fn ($request) => $request['hostname'] === 'ops.acme.test' && $request['ssl']['method'] === 'http' && $request->hasHeader('Authorization', 'Bearer cf-token'));

    $this->actingAs($workspace->owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/settings/Domains')
        ->where('dashboard.state', 'pending')
        ->where('dashboard.records.0.value', 'customers.serveros.com')
        ->where('allowed', true));
});

test('only Business workspaces, only subdomains, never ours', function () {
    [$pro, $proUrl] = domainWorkspace(Plan::Pro, 'acme-pro');
    $this->actingAs($pro->owner)->post($proUrl, ['purpose' => 'dashboard', 'hostname' => 'ops.acme.test'])->assertSessionHasErrors('hostname');

    [$workspace, $url] = domainWorkspace();
    foreach (['acme.test', 'x.'.config('app.domain'), 'not a domain', '10.0.0.1'] as $bad) {
        $this->actingAs($workspace->owner)->post($url, ['purpose' => 'dashboard', 'hostname' => $bad])->assertSessionHasErrors('hostname');
    }
    expect(WorkspaceDomain::query()->count())->toBe(0);
});

test('a live domain serves the dashboard, and links point at it, except MCP', function () {
    [$workspace] = domainWorkspace();
    $domain = liveDomain($workspace);

    expect($domain->state)->toBe('active')
        ->and($domain->verification)->toBeNull()
        ->and($workspace->host())->toBe('ops.acme.test')
        ->and(route('workspace.machines.index', ['workspace' => 'acme']))->toBe('https://ops.acme.test/machines')
        ->and(route('mcp', ['workspace' => 'acme']))->toStartWith('https://acme.'.config('app.domain'))
        ->and(Activity::query()->pluck('action')->all())->toContain('domain.add', 'domain.active');

    $this->actingAs($workspace->owner)->get('https://ops.acme.test/machines')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Machines')->where('workspace.host', 'ops.acme.test'));

    auth()->logout();
    $this->get('https://ops.acme.test/machines')->assertRedirect('https://ops.acme.test/login');

    $this->get('https://elsewhere.acme.test/machines')->assertNotFound();
});

test('the old subdomain hands people over to the domain, signed in', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    $sub = 'https://'.$workspace->subdomain();

    $this->actingAs($workspace->owner)->get("{$sub}/machines")->assertRedirect("{$sub}/domains/go?to=%2Fmachines");
    $go = $this->actingAs($workspace->owner)->get("{$sub}/domains/go?to=/machines")->assertRedirect()->headers->get('Location');
    expect($go)->toStartWith('https://ops.acme.test/_domain/handoff/');

    auth()->logout();
    $this->get($go)->assertRedirect('/machines');
    $this->assertAuthenticatedAs($workspace->owner);

    auth()->logout();
    $this->get($go)->assertRedirect('/login');
    $this->assertGuest();
});

test('a hand-off works only for the browser it was made for', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    $sub = 'https://'.$workspace->subdomain();
    $go = fn () => $this->actingAs($workspace->owner)->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', 'Owner browser')->get("{$sub}/domains/go?to=/machines")->headers->get('Location');

    $link = $go();
    auth()->logout();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeader('User-Agent', 'Owner browser')->get($link)->assertRedirect('/login');
    $this->assertGuest();

    $link = $go();
    auth()->logout();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeader('User-Agent', 'curl')->get($link)->assertRedirect('/login');
    $this->assertGuest();

    $link = $go();
    auth()->logout();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeader('User-Agent', 'Owner browser')->get($link)->assertRedirect('/machines');
    $this->assertAuthenticatedAs($workspace->owner);
});

test('a live domain that stops pointing here stops getting hand-offs', function () {
    [$workspace] = domainWorkspace();
    $domain = liveDomain($workspace);
    expect(CustomDomains::dashboardHostFor($workspace->slug))->toBe('ops.acme.test');

    cfLive([]);
    $domain->forceFill(['checked_at' => now()->subMinutes(11)])->save();
    $this->artisan('domains:check')->assertSuccessful();

    expect($domain->fresh()->state)->not->toBe('active')
        ->and(CustomDomains::dashboardHostFor($workspace->slug))->toBeNull();
});

test('the hand-off carries what a package adds to it, and gives it back there', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    $member = User::factory()->create(['email' => 'ada@acme.com']);
    $workspace->members()->attach($member, ['role' => 'member']);
    $sub = 'https://'.$workspace->subdomain();
    app(Extensions::class)->addSignInHooks(new class extends SignInHooks
    {
        public function handoff(Request $request): array
        {
            return ['ledger' => $request->session()->get('ledger')];
        }

        public function receiveHandoff(Request $request, array $carried, User $user): void
        {
            $request->session()->put('ledger', $carried['ledger'].' for '.$user->email);
        }
    });

    $go = $this->actingAs($member)
        ->withSession([GoogleTeamAccess::VIA => 'google', 'ledger' => 'kept'])
        ->get("{$sub}/domains/go?to=/machines")->headers->get('Location');
    auth()->logout();
    session()->flush();

    $this->get($go)->assertRedirect('/machines');
    expect(session(GoogleTeamAccess::VIA))->toBe('google')
        ->and(session('ledger'))->toBe('kept for ada@acme.com');
});

test('a status page can have its own hostname, or a path on the dashboard’s domain', function () {
    [$workspace] = domainWorkspace();
    $fleet = new WorkspaceStatusPage(['slug' => 'acme-status', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();
    liveDomain($workspace);

    app(CustomDomains::class)->connect($workspace, WorkspaceDomain::STATUS, null, 'uptime');
    $this->get('https://ops.acme.test/uptime')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('status/Show')
        ->where('status.title', $workspace->name)
        ->url('/uptime')
        ->where('jsonUrl', 'https://ops.acme.test/uptime/status.json'));
    $this->get('https://ops.acme.test/uptime/status.json')->assertOk()->assertJsonPath('title', $workspace->name);

    liveDomain($workspace, 'status.acme.test', WorkspaceDomain::STATUS);
    $this->get('https://status.acme.test/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('status/Show'));
    $this->get('https://status.acme.test/badge.svg')->assertOk();
    $this->get('https://ops.acme.test/uptime')->assertNotFound();
});

test('Caddy may get certificates for ours and for connected domains only', function () {
    [$workspace] = domainWorkspace();
    app(CustomDomains::class)->connect($workspace, WorkspaceDomain::DASHBOARD, 'ops.acme.test');
    $ask = fn (string $domain, string $ip = '127.0.0.1') => $this->call('GET', '/api/internal/tls-check', ['domain' => $domain], [], [], ['REMOTE_ADDR' => $ip]);

    expect($ask('ops.acme.test')->status())->toBe(200)
        ->and($ask('acme.'.config('app.domain'))->status())->toBe(200)
        ->and($ask('evil.test')->status())->toBe(404)
        ->and($ask('ops.acme.test', '203.0.113.9')->status())->toBe(404);
});

test('disconnecting removes the hostname from Cloudflare, and the subdomain is home again', function () {
    [$workspace, $url] = domainWorkspace();
    $domain = liveDomain($workspace);

    $this->actingAs($workspace->owner)->delete('https://ops.acme.test/settings/domains/'.$domain->id)
        ->assertRedirect('https://acme.'.config('app.domain').'/settings/domains');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/custom_hostnames/'.$domain->cloudflare_id));
    expect(WorkspaceDomain::query()->count())->toBe(0)
        ->and($workspace->host())->toBe('acme.'.config('app.domain'));
});

test('status.{our domain} serves our own status page, when one is set', function () {
    [$workspace] = domainWorkspace();
    $fleet = new WorkspaceStatusPage(['slug' => 'serveros-qbml', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();
    $host = 'https://status.'.config('app.domain');

    $this->get("{$host}/")->assertRedirect();

    config()->set('serveros.status_page', 'serveros-qbml');
    $this->get("{$host}/")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('status/Show')
        ->url('/')
        ->where('jsonUrl', "{$host}/status.json")
        ->where('meta.image', fn (string $image) => str_starts_with($image, "{$host}/og.png?v=")));
    $this->get("{$host}/status.json")->assertOk()->assertJsonPath('title', $workspace->name);
    $this->get("{$host}/status/serveros-qbml/badge.svg")->assertOk();
});

test('a private status page on its own hostname says so there, with a way to sign in on ours, and settings warn about it', function () {
    [$workspace, $url] = domainWorkspace();
    $fleet = new WorkspaceStatusPage(['slug' => 'acme-status', 'enabled' => true, 'public' => false, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();
    liveDomain($workspace, 'status.acme.test', WorkspaceDomain::STATUS);

    $this->get('https://status.acme.test/')
        ->assertForbidden()
        ->assertSee('This status page is private')
        ->assertSee($workspace->name)
        ->assertSee(rtrim(config('app.url'), '/').'/status/acme-status', false);
    $this->get('https://'.config('app.domain').'/status/acme-status')->assertRedirect();

    $this->actingAs($workspace->owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.hostname', 'status.acme.test')
        ->where('statusPages.0.fleet', true)
        ->where('statusPages.0.public', false)
        ->where('statusPages.0.settingsUrl', 'https://acme.'.config('app.domain').'/settings/status-page'));
});

function domainStatusPage(Workspace $workspace, string $slug): StatusPage
{
    $page = new StatusPage(['slug' => $slug, 'enabled' => true, 'public' => true, 'title' => $workspace->name.' status', 'include_new' => true, 'show_uptime' => true, 'services' => []]);
    $page->machine_id = Machine::factory()->for($workspace)->create()->id;
    $page->save();

    return $page;
}

test('a session handed off to a domain works only there', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    [$other] = domainWorkspace(Plan::Business, 'other');
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);
    $other->members()->attach($member, ['role' => 'admin']);

    $go = $this->actingAs($member)->get('https://'.$workspace->subdomain().'/domains/go?to=/machines')->headers->get('Location');
    auth()->logout();
    session()->flush();

    $this->get($go)->assertRedirect('/machines');
    $this->get('https://ops.acme.test/machines')->assertOk();
    $this->assertAuthenticatedAs($member);

    $this->get('https://'.$other->subdomain().'/machines')->assertRedirect(rtrim(config('app.url'), '/').'/login');
    $this->assertGuest();
    expect(session(BindCustomDomainSession::KEY))->toBeNull();
});

test('account settings on a domain open on ours, and the dashboard stays there', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    $user = $workspace->owner;
    $email = $user->email;
    $main = rtrim(config('app.url'), '/');
    $sub = 'https://'.$workspace->subdomain();

    $this->actingAs($user)->get('https://ops.acme.test/settings/security')->assertRedirect("{$sub}/settings/security");
    $this->actingAs($user)->get('https://ops.acme.test/settings/api')->assertRedirect("{$sub}/settings/api");
    $this->actingAs($user)->get('https://ops.acme.test/settings/security', ['X-Inertia' => 'true'])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', "{$sub}/settings/security");
    $this->actingAs($user)->patch('https://ops.acme.test/settings/profile', ['name' => 'Mallory', 'email' => 'mallory@example.com'])
        ->assertRedirect("{$main}/settings/profile");
    $this->actingAs($user)->get('https://ops.acme.test/oauth/authorize?client_id=x')->assertRedirect("{$main}/oauth/authorize?client_id=x");
    $this->actingAs($user)->post('https://ops.acme.test/user/two-factor-authentication')->assertRedirect("{$main}/user/two-factor-authentication");
    expect($user->fresh()->email)->toBe($email)
        ->and($user->fresh()->name)->not->toBe('Mallory');

    $this->actingAs($user)->get('https://ops.acme.test/machines')->assertOk();

    session()->flush();
    $this->actingAs($user)->get("{$sub}/settings/api")->assertOk();
});

test('a package’s account routes on a domain open on ours too', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    Route::middleware('web')->get('/extension-account', fn () => 'here')->name('extension.account');
    $main = rtrim(config('app.url'), '/');

    $this->actingAs($workspace->owner)->get('https://ops.acme.test/extension-account')->assertOk()->assertSee('here');

    app(Extensions::class)->addAccountRoutes(['extension.account']);
    $this->actingAs($workspace->owner)->get('https://ops.acme.test/extension-account')->assertRedirect("{$main}/extension-account");
});

test('signing in on a domain doesn’t remember the browser', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => 'member']);

    $response = $this->post('https://ops.acme.test/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);

    $this->assertAuthenticatedAs($user);
    expect(collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')))->toBeFalse()
        ->and(session(BindCustomDomainSession::KEY))->toBe('ops.acme.test');
});

test('a dashboard domain serves its own workspace’s status pages only', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    domainStatusPage($workspace, 'acme-web');
    [$other] = domainWorkspace(Plan::Business, 'other');
    domainStatusPage($other, 'account-notice');

    $this->get('https://ops.acme.test/status/acme-web')->assertOk();
    $this->get('https://ops.acme.test/status/account-notice')->assertNotFound();
    $this->get('https://ops.acme.test/status/account-notice/status.json')->assertNotFound();
    $this->get('https://ops.acme.test/status/account-notice/badge.svg')->assertNotFound();
    $this->get('https://ops.acme.test/status/account-notice/embed')->assertNotFound();
    $this->get('https://ops.acme.test/status/account-notice/og.png')->assertNotFound();
    $this->get('https://ops.acme.test/status/account-notice/feed.atom')->assertNotFound();
    $this->postJson('https://ops.acme.test/status/account-notice/subscribe', ['email' => 'a@example.com'])->assertNotFound();
    $this->get('https://'.config('app.domain').'/status/account-notice')->assertOk();
});

test('a status domain follows its page when it’s renamed, and never serves a page someone else takes', function () {
    [$workspace] = domainWorkspace();
    $page = domainStatusPage($workspace, 'acme-web');
    app(CustomDomains::class)->connect($workspace, WorkspaceDomain::STATUS, 'status.acme.test', page: 'acme-web');
    cfLive(['status.acme.test']);
    app(CustomDomains::class)->refresh(WorkspaceDomain::query()->sole());
    $this->get('https://status.acme.test/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('status.title', $workspace->name.' status'));

    $page->update(['slug' => 'acme-web-2']);
    expect(WorkspaceDomain::query()->sole()->status_page_slug)->toBe('acme-web-2');
    $this->get('https://status.acme.test/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('status.title', $workspace->name.' status'));

    [$other] = domainWorkspace(Plan::Business, 'other');
    domainStatusPage($other, 'acme-web');
    $page->machine->delete();
    domainStatusPage($other, 'acme-web-2');
    $this->get('https://status.acme.test/')->assertNotFound();
});

test('a session on a domain can’t reach the member’s other workspaces', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    [$other] = domainWorkspace(Plan::Business, 'other');
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);
    $other->members()->attach($member, ['role' => 'admin']);
    $machine = Machine::factory()->for($other)->create(['name' => 'billing-db']);
    $main = rtrim(config('app.url'), '/');

    $this->actingAs($member)->get('https://ops.acme.test/workspaces/movable-machines', ['Accept' => 'application/json'])
        ->assertRedirect("{$main}/workspaces/movable-machines");
    $this->actingAs($member)->get('https://ops.acme.test/workspaces')->assertRedirect("{$main}/workspaces");
    $this->actingAs($member)->post('https://ops.acme.test/workspaces', ['name' => 'Taken', 'machines' => [$machine->id]])
        ->assertRedirect("{$main}/workspaces");
    $this->actingAs($member)->post('https://ops.acme.test/invitations/abc/accept')->assertRedirect("{$main}/invitations/abc/accept");

    expect($machine->fresh()->workspace_id)->toBe($other->id)
        ->and(Workspace::query()->where('name', 'Taken')->exists())->toBeFalse();
});

test('a session from a domain ends once the domain is gone', function () {
    [$workspace] = domainWorkspace();
    $domain = liveDomain($workspace);
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)->get('https://ops.acme.test/machines')->assertOk();
    expect(session(BindCustomDomainSession::KEY))->toBe('ops.acme.test');

    $domain->delete();
    CustomDomains::forget();

    $this->get('https://ops.acme.test/settings/profile')->assertRedirect();
    $this->assertGuest();
});
