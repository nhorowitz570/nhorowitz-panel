<?php

use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\SendToInstaller;
use App\Http\Middleware\TrustCloudflare;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Domains\DomainCheck;
use App\Support\Domains\DomainResolver;
use App\Support\Install\AddressCheck;
use App\Support\Machines\CloudflareTunnel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const TUNNEL_ID = '6ff42ae2-765d-4adf-8112-31c55c1551ef';

function tunnelToken(string $id = TUNNEL_ID): string
{
    return base64_encode((string) json_encode(['a' => 'b4ab0c1d2e3f40516273849506a7b8c9', 't' => $id, 's' => base64_encode(random_bytes(32))]));
}

/** @return array{0: Workspace, 1: Machine, 2: string} */
function tunnelMachine(string $daemon = '0.1.1-pre.37'): array
{
    config()->set('serveros.edition', 'self-hosted');
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('daemon.tunnel_since', '0.1.1-pre.37');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.7']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $daemon])->save();

    return [$workspace, $machine, 'https://'.$workspace->host()."/machines/{$machine->id}"];
}

/** @return list<array<string, mixed>> */
function tunnelJobsSent(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/commands'))->map(fn ($r) => $r['command']['job'])->values()->all();
}

function finishTunnelJob(Machine $machine, DaemonJob $job, string $state, array $result = []): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [[
            'v' => 1, 'kind' => 'job_update',
            'payload' => ['job_id' => $job->id, 'state' => $state, 'result' => $result],
        ]]])
        ->assertOk();
}

test('a tunnel token gives up its tunnel id, and anything else doesn’t', function () {
    expect(CloudflareTunnel::tunnelId(tunnelToken()))->toBe(TUNNEL_ID)
        ->and(CloudflareTunnel::tunnelId(rtrim(strtr(tunnelToken(), '+/', '-_'), '=')))->toBe(TUNNEL_ID)
        ->and(CloudflareTunnel::tunnelId('not a token'))->toBeNull()
        ->and(CloudflareTunnel::tunnelId(base64_encode('{"a":"x","t":"not-a-uuid","s":"y"}')))->toBeNull()
        ->and(CloudflareTunnel::tunnelId(base64_encode('{"t":"'.TUNNEL_ID.'"}')))->toBeNull();
});

test('connecting a tunnel sends the token to the machine and records who did it', function () {
    [$workspace, $machine, $url] = tunnelMachine();
    $token = tunnelToken();

    $jobId = $this->actingAs($workspace->owner)->postJson("{$url}/tunnel", ['token' => $token])->assertAccepted()->json('jobId');

    expect(tunnelJobsSent())->toBe([['type' => 'tunnel', 'action' => 'up', 'token' => $token]])
        ->and(Activity::query()->where('action', 'machine.tunnel')->first()->meta)->toBe(['action' => 'up']);

    $this->getJson("{$url}/tunnel/{$jobId}")->assertOk()->assertJson(['state' => 'queued', 'tunnel' => null]);
    $this->postJson("{$url}/tunnel", ['token' => $token])->assertStatus(409);
});

test('the whole install command works as well as the token', function () {
    [$workspace, , $url] = tunnelMachine();
    $token = tunnelToken();

    $this->actingAs($workspace->owner)->postJson("{$url}/tunnel", ['token' => "sudo cloudflared service install {$token}\n"])->assertAccepted();

    expect(tunnelJobsSent()[0]['token'])->toBe($token);
});

test('a token that isn’t one, an old daemon and the cloud edition are turned away', function () {
    [$workspace, , $url] = tunnelMachine();
    $this->actingAs($workspace->owner)->postJson("{$url}/tunnel", ['token' => 'hunter2'])->assertStatus(422)->assertJsonValidationErrors('token');

    [$workspace, , $url] = tunnelMachine('0.1.1-pre.36');
    expect($this->actingAs($workspace->owner)->postJson("{$url}/tunnel", ['token' => tunnelToken()])->assertStatus(409)->json('error'))->toContain('0.1.1-pre.37');

    [$workspace, , $url] = tunnelMachine();
    config()->set('serveros.edition', 'cloud');
    $this->actingAs($workspace->owner)->postJson("{$url}/tunnel", ['token' => tunnelToken()])->assertNotFound();

    expect(tunnelJobsSent())->toBe([]);
});

test('when the machine has it running, the panel keeps the tunnel and forgets the token', function () {
    [$workspace, $machine, $url] = tunnelMachine();
    $jobId = $this->actingAs($workspace->owner)->postJson("{$url}/tunnel", ['token' => tunnelToken()])->json('jobId');
    $job = DaemonJob::query()->findOrFail($jobId);

    finishTunnelJob($machine, $job, 'succeeded', ['tunnel' => ['state' => 'connected', 'tunnel_id' => TUNNEL_ID, 'proxy' => 'caddy', 'plain_http' => true]]);

    expect($machine->fresh()->tunnel)->toMatchArray(['provider' => 'cloudflare', 'tunnel_id' => TUNNEL_ID, 'state' => 'connected', 'plain_http' => true])
        ->and($job->fresh()->command['job'])->toBe(['type' => 'tunnel', 'action' => 'up'])
        ->and(CloudflareTunnel::target($machine->fresh()))->toBe(TUNNEL_ID.'.cfargotunnel.com');

    $settings = $this->get("{$url}/settings")->assertOk()->viewData('page')['props']['tunnel'];
    expect($settings)->toMatchArray(['supported' => true, 'target' => TUNNEL_ID.'.cfargotunnel.com', 'changing' => null]);
});

test('a failed connection keeps no tunnel but still forgets the token', function () {
    [$workspace, $machine, $url] = tunnelMachine();
    $job = DaemonJob::query()->findOrFail($this->actingAs($workspace->owner)->postJson("{$url}/tunnel", ['token' => tunnelToken()])->json('jobId'));

    finishTunnelJob($machine, $job, 'failed');

    expect($machine->fresh()->tunnel)->toBeNull()
        ->and($job->fresh()->command['job'])->not->toHaveKey('token');
});

test('taking the tunnel off sends down and clears it once the machine is done', function () {
    [$workspace, $machine, $url] = tunnelMachine();
    $machine->forceFill(['tunnel' => ['provider' => 'cloudflare', 'tunnel_id' => TUNNEL_ID, 'state' => 'connected', 'plain_http' => true, 'since' => now()->toIso8601String()]])->save();

    $job = DaemonJob::query()->findOrFail($this->actingAs($workspace->owner)->deleteJson("{$url}/tunnel")->assertAccepted()->json('jobId'));
    expect(tunnelJobsSent())->toBe([['type' => 'tunnel', 'action' => 'down']]);

    finishTunnelJob($machine, $job, 'succeeded', ['tunnel' => ['state' => 'removed', 'tunnel_id' => null, 'proxy' => 'caddy', 'plain_http' => false]]);

    expect($machine->fresh()->tunnel)->toBeNull();
});

test('the settings page only offers a tunnel on self-hosted installs', function () {
    [$workspace, , $url] = tunnelMachine();
    expect($this->actingAs($workspace->owner)->get("{$url}/settings")->assertOk()->viewData('page')['props']['tunnel'])->not->toBeNull();

    config()->set('serveros.edition', 'cloud');
    expect($this->get("{$url}/settings")->assertOk()->viewData('page')['props']['tunnel'])->toBeNull();
});

test('behind a tunnel, an app’s domain on Cloudflare is ready, and the record to add is a CNAME to the tunnel', function () {
    [, $machine] = tunnelMachine();
    $machine->forceFill(['tunnel' => ['provider' => 'cloudflare', 'tunnel_id' => TUNNEL_ID, 'state' => 'connected', 'plain_http' => true, 'since' => now()->toIso8601String()]])->save();
    $resolver = new class extends DomainResolver
    {
        public function addresses(string $host): array
        {
            return str_starts_with($host, 'shop.') ? ['104.21.10.20'] : [];
        }
    };
    $check = new DomainCheck($resolver);

    expect($check->check($machine, 'shop.example.com'))->toMatchArray(['status' => 'ready', 'expected' => TUNNEL_ID.'.cfargotunnel.com'])
        ->and($check->check($machine, 'blog.example.com'))->toMatchArray([
            'status' => 'missing',
            'record' => ['type' => 'CNAME', 'name' => 'blog.example.com', 'value' => TUNNEL_ID.'.cfargotunnel.com'],
        ]);

    $machine->forceFill(['tunnel' => null])->save();
    expect($check->check($machine, 'shop.example.com')['status'])->toBe('proxied');
});

test('behind a tunnel, the panel believes cloudflared about who’s visiting, and only then', function () {
    $request = function (): Request {
        $request = Request::create('http://panel.example.com/', 'GET', server: ['REMOTE_ADDR' => '172.18.0.9']);
        $request->headers->set('CF-Connecting-IP', '198.51.100.23');
        $request->headers->set('X-Forwarded-Proto', 'https');

        return $request;
    };
    $seen = fn (Request $r) => (new TrustCloudflare)->handle($r, fn (Request $r) => response(($r->secure() ? 'https ' : 'http ').$r->ip()))->getContent();

    expect($seen($request()))->toBe('http 172.18.0.9');

    config()->set('serveros.tunnel', 'cloudflare');
    expect($seen($request()))->toBe('https 198.51.100.23');
});

test('the visitor’s address and HTTPS are settled before anything else runs, the installer’s redirect too', function () {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect(array_search(TrustCloudflare::class, $global, true))->toBeLessThan(array_search(SendToInstaller::class, $global, true))
        ->and(array_search(SendToInstaller::class, $global, true))->toBeLessThan(array_search(ResolveCustomDomain::class, $global, true));
});

test('the installer’s address step checks the tunnel and the gateway’s own name', function () {
    config()->set('serveros.tunnel', 'cloudflare');
    config()->set('daemon.gateway_host', 'gateway.example.com:8443');
    $request = Request::create('https://example.com/install');
    $dns = ['example.com' => ['104.21.10.20'], 'gateway.example.com' => ['203.0.113.7']];
    $check = fn (array $dns) => new AddressCheck(fn (string $host) => $dns[$host] ?? (str_starts_with($host, 'serveros-check-') ? ['104.21.10.20'] : []));

    $result = $check($dns)->check('example.com', $request);
    expect($result['status'])->toBe('ok')
        ->and(collect($result['lines'])->pluck('text')->last())->toContain('gateway.example.com (203.0.113.7)');

    $result = $check([...$dns, 'gateway.example.com' => ['172.67.1.1']])->check('example.com', $request);
    expect($result['status'])->toBe('fail')
        ->and(collect($result['lines'])->pluck('text')->last())->toContain('DNS only');

    config()->set('daemon.gateway_host', 'panel.example.com:8443');
    $result = $check(['panel.example.com' => ['104.21.10.20']])->check('panel.example.com', Request::create('https://panel.example.com/install'));
    expect($result['status'])->toBe('fail')
        ->and(collect($result['lines'])->pluck('text')->implode(' '))->toContain('Advanced Certificate Manager')->toContain('SERVEROS_GATEWAY_HOST');
});
