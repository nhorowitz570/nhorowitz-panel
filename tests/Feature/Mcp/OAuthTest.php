<?php

use App\Enums\Plan;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Mcp\OAuthTokenController;
use App\Mcp\Scopes;
use App\Mcp\Tools\WorkspaceTools;
use App\Models\Activity;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthCode;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Mcp\OAuth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Passkeys\Passkey;

beforeEach(fn () => config()->set('mcp.groups', [WorkspaceTools::class]));

function oauthClient(array $uris = ['https://claude.ai/api/mcp/auth_callback'], string $name = 'Claude'): McpOAuthClient
{
    $response = test()->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => $uris])->assertCreated();

    return McpOAuthClient::query()->where('client_id', $response->json('client_id'))->sole();
}

/** @return array{0: string, 1: string} */
function pkce(): array
{
    $verifier = Str::random(64);

    return [$verifier, OAuthTokenController::challenge($verifier)];
}

/** @param  array<string, string>  $extra */
function authorizeRequest(User $user, McpOAuthClient $client, string $challenge, array $extra = []): string
{
    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $client->client_id,
        'redirect_uri' => $client->redirect_uris[0],
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => 'xyz',
        'scope' => implode(' ', Scopes::ALL),
        ...$extra,
    ]);
    $nonce = null;
    test()->actingAs($user)->get('/oauth/authorize?'.$query)
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertInertia(function (Assert $page) use (&$nonce) {
            $page->component('auth/OAuthAuthorize');
            $nonce = $page->toArray()['props']['request'] ?? null;
        });

    return (string) $nonce;
}

function approve(User $user, string $nonce, array $choices = ['write' => true]): string
{
    $location = test()->actingAs($user)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', ...$choices])->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
    expect($params['state'])->toBe('xyz')->and($params['iss'])->toBe(OAuth::issuer());

    return $params['code'];
}

test('discovery: protected resource and authorization server metadata', function () {
    $workspace = Workspace::factory()->create();

    $this->getJson('https://'.$workspace->host().'/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('resource', 'https://'.$workspace->host().'/mcp')
        ->assertJsonPath('authorization_servers.0', OAuth::issuer());

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('issuer', OAuth::issuer())
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);
});

test('registration only takes safe redirect URIs', function () {
    $this->postJson('/oauth/register', ['redirect_uris' => ['javascript:alert(1)']])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    $this->postJson('/oauth/register', ['redirect_uris' => ['http://evil.example/cb']])->assertStatus(400);
    $this->postJson('/oauth/register', ['redirect_uris' => ['https://a.example/cb#frag']])->assertStatus(400);
    $this->postJson('/oauth/register', ['redirect_uris' => []])->assertStatus(400);

    $this->postJson('/oauth/register', ['client_name' => 'Cursor', 'redirect_uris' => ['cursor://anysphere.cursor-retrieval/oauth/callback', 'http://127.0.0.1:33418/callback']])
        ->assertCreated()
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('client_name', 'Cursor');
});

test('the whole flow: consent, code with PKCE, MCP access, refresh rotation, revoke', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $user = $workspace->owner;
    $client = oauthClient();
    [$verifier, $challenge] = pkce();

    $nonce = authorizeRequest($user, $client, $challenge, ['resource' => OAuth::resource($workspace)]);
    $code = approve($user, $nonce);

    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => Str::random(64)])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    $tokens = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => $verifier])
        ->assertOk()->assertHeader('Cache-Control')->json();
    expect($tokens['token_type'])->toBe('Bearer')->and($tokens['scope'])->toBe('workspace:read workspace:write');

    $result = mcpCall($workspace, $tokens['access_token'], 'get_workspace');
    expect($result['data']['connection']['name'])->toBe('Claude')
        ->and($result['data']['connection']['canMakeChanges'])->toBeTrue()
        ->and($result['data']['connection']['canRunCommands'])->toBeFalse();

    $fresh = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client->client_id])->assertOk()->json();
    mcpRequest($workspace, $tokens['access_token'], 'ping')->assertUnauthorized();
    mcpRequest($workspace, $fresh['access_token'], 'ping')->assertOk();
    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client->client_id])->assertStatus(400);

    $this->postJson('/oauth/revoke', ['token' => $fresh['refresh_token'], 'client_id' => $client->client_id])->assertOk();
    mcpRequest($workspace, $fresh['access_token'], 'ping')->assertUnauthorized();
});

test('tokens are bound to the workspace approved, and expire', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->for($workspace->owner, 'owner')->create();
    $other->members()->syncWithoutDetaching([$workspace->owner_id => ['role' => WorkspaceRole::Owner->value]]);
    $client = oauthClient();
    [$verifier, $challenge] = pkce();

    $code = approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge, ['resource' => OAuth::resource($workspace)]));
    $access = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => $verifier])->json('access_token');

    mcpRequest($other, $access, 'ping')->assertUnauthorized();
    mcpRequest($workspace, $access, 'ping')->assertOk();

    $this->travel(McpToken::ACCESS_MINUTES + 1)->minutes();
    mcpRequest($workspace, $access, 'ping')->assertUnauthorized();
});

test('the person chooses: read only by default without changes ticked, and no commands without two-factor', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient();
    [$verifier, $challenge] = pkce();

    $code = approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge), ['workspace' => $workspace->id, 'write' => false]);
    expect(McpOAuthCode::query()->sole()->scopes)->toBe([Scopes::READ]);

    $nonce = authorizeRequest($workspace->owner, $client, $challenge);
    $this->actingAs($workspace->owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'workspace' => $workspace->id, 'write' => true, 'commands' => true])
        ->assertSessionHasErrors('commands');
});

test('deny goes back with access_denied; bad clients and redirects never redirect', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient();
    [, $challenge] = pkce();

    $nonce = authorizeRequest($workspace->owner, $client, $challenge);
    $location = $this->actingAs($workspace->owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'deny'])->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith($client->redirect_uris[0].'?error=access_denied');

    $this->actingAs($workspace->owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'workspace' => $workspace->id])
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));

    $this->actingAs($workspace->owner)->get('/oauth/authorize?client_id=nope&response_type=code')->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));
    $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $client->client_id, 'redirect_uri' => 'https://evil.example/cb', 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));

    $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'response_type' => 'code', 'state' => 's']))
        ->assertRedirect()->assertRedirectContains('error=invalid_request');
});

test('a workspace you are not in can’t be approved', function () {
    $workspace = Workspace::factory()->create();
    $stranger = User::factory()->create();
    Workspace::factory()->for($stranger, 'owner')->create();
    $client = oauthClient();
    [, $challenge] = pkce();

    $this->actingAs($stranger)->get('/oauth/authorize?'.http_build_query(['client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => OAuth::resource($workspace)]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));

    $nonce = authorizeRequest($stranger, $client, $challenge);
    $this->actingAs($stranger)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'workspace' => $workspace->id])->assertSessionHasErrors('workspace');
});

test('the consent page says when it can vouch for the app', function () {
    $workspace = Workspace::factory()->create();
    [, $challenge] = pkce();

    $claude = oauthClient();
    $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $claude->client_id, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))
        ->assertInertia(fn (Assert $page) => $page->where('client.known', 'claude')->where('client.redirectHost', 'claude.ai'));

    $unknown = oauthClient(['https://totally-claude.example/cb'], 'Claude');
    $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $unknown->client_id, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))
        ->assertInertia(fn (Assert $page) => $page->where('client.known', null));
});

test('spent MCP access is cleared away, live connections are kept', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient();
    $idle = oauthClient(['https://example.com/cb'], 'Never used');
    $idle->forceFill(['created_at' => now()->subDays(40)])->save();
    $live = McpToken::query()->forceCreate([
        'workspace_id' => $workspace->id, 'user_id' => $workspace->owner->id, 'mcp_oauth_client_id' => $client->id,
        'kind' => 'oauth', 'name' => 'Claude', 'scopes' => Scopes::ALL, 'token_hash' => McpToken::hash('a'), 'token_prefix' => 'soa_a',
        'expires_at' => now()->subHour(), 'refresh_hash' => McpToken::hash('r'), 'refresh_expires_at' => now()->addDays(20),
    ]);
    $ended = $live->replicate()->forceFill(['token_hash' => McpToken::hash('b'), 'refresh_hash' => McpToken::hash('s'), 'refresh_expires_at' => now()->subDays(31)]);
    $ended->save();
    [$revoked] = McpToken::issueKey($workspace, $workspace->owner, 'Old key', Scopes::ALL);
    $revoked->forceFill(['revoked_at' => now()->subDays(31)])->save();
    $call = McpToolCall::query()->forceCreate(['workspace_id' => $workspace->id, 'tool' => 'get_workspace', 'arguments' => [], 'ok' => true, 'duration_ms' => 1, 'created_at' => now()->subDays(31)]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(McpToken::query()->pluck('id')->all())->toBe([$live->id])
        ->and(McpToolCall::query()->find($call->id))->toBeNull()
        ->and($client->fresh())->not->toBeNull()
        ->and($idle->fresh())->toBeNull();
});

test('connecting an app through MCP shows in the workspace’s Activity; saying no does not', function () {
    $workspace = Workspace::factory()->create();
    $workspace->owner->forceFill(['name' => 'Sam'])->save();
    $client = oauthClient(name: 'Claude Desktop');
    [, $challenge] = pkce();

    $nonce = authorizeRequest($workspace->owner, $client, $challenge);
    $this->actingAs($workspace->owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'deny']);
    approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge), ['workspace' => $workspace->id, 'write' => false]);

    $entry = Activity::query()->sole();
    expect($entry->action)->toBe('mcp.connect')
        ->and($entry->meta)->toBe(['scopes' => [Scopes::READ]])
        ->and(ActivityLine::sentence($entry))->toBe('Sam connected Claude Desktop through MCP');
});

test('a revoke from the app is its own doing, and a token for a package’s audience has no workspace to tell', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $sam = $workspace->owner;
    $sam->forceFill(['name' => 'Sam'])->save();
    $boss = User::factory()->admin()->create();
    $client = oauthClient();
    $issue = function (?Workspace $for, User $user) use ($client): string {
        $secret = McpToken::secret('access');
        McpToken::query()->create([
            'audience' => $for === null ? 'tickets' : McpToken::WORKSPACE,
            'workspace_id' => $for?->id,
            'user_id' => $user->id,
            'mcp_oauth_client_id' => $client->id,
            'kind' => 'oauth',
            'name' => $client->name,
            'scopes' => $for === null ? [] : Scopes::ALL,
            'token_hash' => McpToken::hash($secret),
            'token_prefix' => substr($secret, 0, 12),
            'expires_at' => now()->addHour(),
        ]);

        return $secret;
    };

    $this->postJson('/oauth/revoke', ['token' => $issue($workspace, $sam), 'client_id' => $client->client_id])->assertOk();
    $this->postJson('/oauth/revoke', ['token' => $issue(null, $boss), 'client_id' => $client->client_id])->assertOk();

    expect(McpToken::query()->whereNull('revoked_at')->count())->toBe(0);
    $gone = Activity::query()->sole();
    expect($gone->workspace_id)->toBe($workspace->id)
        ->and($gone->user_id)->toBeNull()
        ->and($gone->source)->toBe('api')
        ->and($gone->ip)->toBeNull()
        ->and(ActivityLine::sentence($gone))->toBe('Claude disconnected itself from MCP (Sam’s connection)');
});

test('connecting an app over MCP to a workspace that requires two-factor needs a passkey sign-in, not just a passkey', function () {
    $workspace = Workspace::factory()->create(['require_two_factor' => true]);
    $owner = $workspace->owner;
    Passkey::query()->forceCreate(['user_id' => $owner->id, 'name' => 'Laptop', 'credential_id' => 'abc', 'credential' => []]);
    $client = oauthClient();
    [, $challenge] = pkce();

    $this->withSession([GoogleTeamAccess::VIA => 'password']);
    $nonce = authorizeRequest($owner, $client, $challenge);
    $this->actingAs($owner)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'workspace' => $workspace->id])
        ->assertSessionHasErrors('workspace');
    expect(McpOAuthCode::query()->count())->toBe(0);

    $this->withSession([GoogleTeamAccess::VIA => 'passkey']);
    approve($owner, authorizeRequest($owner, $client, $challenge), ['workspace' => $workspace->id]);
    expect(McpOAuthCode::query()->count())->toBe(1);
});

test('a self-registered app’s broken request shows our error page instead of sending you to it', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient(['https://evil.example/landing'], 'Anything');
    [, $challenge] = pkce();

    foreach ([
        ['response_type' => 'token'],
        ['response_type' => 'code'],
        ['response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => 'https://elsewhere.example/mcp'],
    ] as $query) {
        $this->actingAs($workspace->owner)->get('/oauth/authorize?'.http_build_query(['client_id' => $client->client_id, 'redirect_uri' => 'https://evil.example/landing', 'state' => 's', ...$query]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('problem'));
    }
});

test('using a code a second time revokes the access it gave', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient();
    [$verifier, $challenge] = pkce();
    $code = approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge, ['resource' => OAuth::resource($workspace)]));
    $exchange = ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => $verifier];

    $tokens = $this->postJson('/oauth/token', $exchange)->assertOk()->json();
    mcpRequest($workspace, $tokens['access_token'], 'ping')->assertOk();

    $this->postJson('/oauth/token', $exchange)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    mcpRequest($workspace, $tokens['access_token'], 'ping')->assertUnauthorized();
    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client->client_id])->assertStatus(400);
});

test('the token endpoint wants the redirect_uri back when the app sent one to authorize', function () {
    $workspace = Workspace::factory()->create();
    $client = oauthClient();
    [$verifier, $challenge] = pkce();
    $exchange = fn (string $code) => ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'code_verifier' => $verifier];

    $sent = approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge, ['resource' => OAuth::resource($workspace)]));
    $this->postJson('/oauth/token', $exchange($sent))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    $this->postJson('/oauth/token', [...$exchange($sent), 'redirect_uri' => $client->redirect_uris[0]])->assertOk();

    $left = approve($workspace->owner, authorizeRequest($workspace->owner, $client, $challenge, ['resource' => OAuth::resource($workspace), 'redirect_uri' => null]));
    $this->postJson('/oauth/token', $exchange($left))->assertOk();
});

test('a workspace that needs Google sign-in can’t be connected from a password session', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $workspace->forceFill(['require_google_sign_in' => true])->save();
    $workspace->emailDomains()->create(['domain' => 'acme.com'])->forceFill(['verified_at' => now()])->save();
    $sam = User::factory()->create(['email' => 'sam@acme.com']);
    $workspace->members()->attach($sam, ['role' => 'admin']);
    $own = Workspace::factory()->for($sam, 'owner')->create(['name' => 'Side project']);
    $own->members()->syncWithoutDetaching([$sam->id => ['role' => WorkspaceRole::Owner->value]]);
    $client = oauthClient();
    [, $challenge] = pkce();
    $query = fn (array $extra = []) => '/oauth/authorize?'.http_build_query(['client_id' => $client->client_id, 'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz', ...$extra]);

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'password'])->get($query(['resource' => OAuth::resource($workspace)]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('problem', fn (string $problem) => str_contains($problem, 'sign in with Google')));

    $nonce = null;
    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'password'])->get($query())
        ->assertInertia(function (Assert $page) use (&$nonce, $own) {
            $page->has('workspaces', 1)->where('workspaces.0.id', $own->id);
            $nonce = $page->toArray()['props']['request'];
        });
    $this->actingAs($sam)->post('/oauth/authorize', ['request' => $nonce, 'decision' => 'approve', 'workspace' => $workspace->id, 'write' => true])
        ->assertSessionHasErrors('workspace');
    expect(McpOAuthCode::query()->exists())->toBeFalse();

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'google', GoogleTeamAccess::HD => 'acme.com'])->get($query(['resource' => OAuth::resource($workspace)]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->missing('problem')->where('workspaces.0.id', $workspace->id));
});

test('turning on Google sign-in disconnects members’ AI apps, but not the owner’s', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $workspace->emailDomains()->create(['domain' => 'acme.com'])->forceFill(['verified_at' => now()])->save();
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'admin']);
    $client = oauthClient();
    $connect = fn (User $user) => McpToken::query()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id, 'mcp_oauth_client_id' => $client->id, 'kind' => 'oauth', 'name' => 'Claude',
        'scopes' => Scopes::ALL, 'token_hash' => McpToken::hash(Str::random()), 'token_prefix' => 'soa_x', 'expires_at' => now()->addHour(),
    ]);
    $samApp = $connect($sam);
    $ownerApp = $connect($workspace->owner);
    [$samKey] = McpToken::issueKey($workspace, $sam, 'Laptop', Scopes::ALL);

    $this->actingAs($workspace->owner)->put('https://'.$workspace->host().'/settings/require-google', ['require' => true])->assertSessionHasNoErrors();

    expect($samApp->fresh()->revoked_at)->not->toBeNull()
        ->and($ownerApp->fresh()->revoked_at)->toBeNull()
        ->and($samKey->fresh()->revoked_at)->toBeNull();
});

test('allowing an app to run commands needs a fresh two-factor code', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);
    $client = oauthClient();
    [, $challenge] = pkce();
    $choices = ['workspace' => $workspace->id, 'write' => true, 'commands' => true];

    $this->actingAs($owner)->post('/oauth/authorize', ['request' => authorizeRequest($owner, $client, $challenge), 'decision' => 'approve', ...$choices])
        ->assertSessionHasErrors('commands');
    $this->actingAs($owner)->post('/oauth/authorize', ['request' => authorizeRequest($owner, $client, $challenge), 'decision' => 'approve', ...$choices, 'code' => '000000'])
        ->assertSessionHasErrors('commands');
    expect(McpOAuthCode::query()->exists())->toBeFalse();

    approve($owner, authorizeRequest($owner, $client, $challenge), [...$choices, 'code' => '123456']);
    expect(McpOAuthCode::query()->sole()->scopes)->toBe(Scopes::ALL);
});

test('someone removed from a workspace can’t keep their app connection alive', function () {
    $workspace = Workspace::factory()->create();
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'admin']);
    $client = oauthClient();
    [$verifier, $challenge] = pkce();
    $code = approve($sam, authorizeRequest($sam, $client, $challenge, ['resource' => OAuth::resource($workspace)]));
    $tokens = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client->client_id, 'redirect_uri' => $client->redirect_uris[0], 'code_verifier' => $verifier])->assertOk()->json();

    $workspace->members()->detach($sam->id);

    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client->client_id])->assertStatus(400);
    expect(McpToken::query()->sole()->revoked_at)->not->toBeNull();
});
