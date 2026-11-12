<?php

use App\Enums\WorkspaceRole;
use App\Mcp\Scopes;
use App\Mcp\Tools\WorkspaceTools;
use App\Models\Activity;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

beforeEach(fn () => config()->set('mcp.groups', [WorkspaceTools::class]));

function mcpSettings(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/settings/mcp'.$path;
}

test('keys need the experimental terms accepted first, and are shown once', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;

    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Laptop', 'access' => 'write'])->assertStatus(422);

    $owner->forceFill(['mcp_terms_accepted_at' => now()])->save();
    $secret = $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Laptop', 'access' => 'write'])
        ->assertCreated()->json('secret');

    expect($secret)->toStartWith(McpToken::KEY_PREFIX)
        ->and(McpToken::query()->sole())->scopes->toBe([Scopes::READ, Scopes::WRITE])->token_hash->not->toBe($secret)
        ->and(Activity::query()->where('action', 'mcp.key_create')->exists())->toBeTrue();
    mcpRequest($workspace, $secret, 'ping')->assertOk();

    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Shell', 'access' => 'commands'])->assertStatus(422)->assertJsonValidationErrors('access');

    $this->actingAs($owner)->get(mcpSettings($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/settings/Mcp')
            ->where('mcpUrl', 'https://'.$workspace->host().'/mcp')
            ->where('connections.0.name', 'Laptop')
            ->where('connections.0.prefix', substr($secret, 0, 12))
            ->missing('connections.0.secret'));
});

test('an MCP key that runs commands needs a fresh two-factor code, like the console', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->forceFill(['mcp_terms_accepted_at' => now(), 'two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Shell', 'access' => 'commands'])
        ->assertUnprocessable()->assertJsonValidationErrors('code')->assertJson(['code' => 'console_locked']);
    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Shell', 'access' => 'commands', 'code' => '000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    expect(McpToken::query()->count())->toBe(0);

    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Shell', 'access' => 'commands', 'code' => '123456'])
        ->assertCreated()->assertJsonPath('scopes', Scopes::ALL);
    $this->actingAs($owner)->get(mcpSettings($workspace))->assertInertia(fn (Assert $page) => $page->where('consoleUnlocked', true));
});

test('a wrong code for a command key can’t be tried over and over', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->forceFill(['mcp_terms_accepted_at' => now(), 'two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    foreach (range(1, 5) as $try) {
        $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Shell', 'access' => 'commands', 'code' => '000000'])->assertUnprocessable();
    }
    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Shell', 'access' => 'commands', 'code' => '123456'])
        ->assertUnprocessable()->assertJsonPath('errors.code.0', 'Too many tries. Wait a minute, then use a fresh code.');
    expect(McpToken::query()->count())->toBe(0);
});

test('people see their own connections; those who manage members see and revoke everyone’s', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['mcp_terms_accepted_at' => now()]);
    $workspace->members()->attach($member, ['role' => WorkspaceRole::Member->value]);
    [$ownersKey] = McpToken::issueKey($workspace, $workspace->owner, 'Owner key', Scopes::ALL);
    [$membersKey, $secret] = McpToken::issueKey($workspace, $member, 'Member key', [Scopes::READ]);

    $this->actingAs($member)->get(mcpSettings($workspace))->assertInertia(fn (Assert $page) => $page->has('connections', 1)->where('connections.0.name', 'Member key'));
    $this->actingAs($member)->delete(mcpSettings($workspace, "/connections/{$ownersKey->id}"))->assertForbidden();

    $this->actingAs($workspace->owner)->get(mcpSettings($workspace))->assertInertia(fn (Assert $page) => $page->has('connections', 2)->where('seesAll', true));
    $this->actingAs($workspace->owner)->delete(mcpSettings($workspace, "/connections/{$membersKey->id}"))->assertRedirect();
    mcpRequest($workspace, $secret, 'ping')->assertUnauthorized();
});

test('another workspace’s connection can’t be touched from here', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    [$key] = McpToken::issueKey($other, $other->owner, 'Theirs', Scopes::ALL);

    $this->actingAs($workspace->owner)->delete(mcpSettings($workspace, "/connections/{$key->id}"))->assertNotFound();
    expect($key->fresh()->revoked_at)->toBeNull();
});

test('a key that can run commands needs a fresh two-factor code, for MCP and the API alike', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->forceFill(['mcp_terms_accepted_at' => now(), 'two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    foreach (['/settings/mcp/keys', '/settings/api/keys'] as $path) {
        $url = 'https://'.$workspace->host().$path;
        $this->actingAs($owner)->postJson($url, ['name' => 'Shell', 'access' => 'commands'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->actingAs($owner)->postJson($url, ['name' => 'Shell', 'access' => 'commands', 'code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->actingAs($owner)->postJson($url, ['name' => 'Laptop', 'access' => 'write'])->assertCreated();
    }
    expect(McpToken::query()->get()->every(fn (McpToken $t) => ! $t->allows(Scopes::COMMANDS)))->toBeTrue();

    $this->actingAs($owner)->postJson(mcpSettings($workspace, '/keys'), ['name' => 'Shell', 'access' => 'commands', 'code' => '123456'])
        ->assertCreated()->assertJsonPath('scopes', Scopes::ALL);
});

test('wrong two-factor codes share one limit across the console and the key forms', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->forceFill(['mcp_terms_accepted_at' => now(), 'two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);
    $host = 'https://'.$workspace->host();

    foreach (['/settings/mcp/keys', '/settings/api/keys', '/settings/mcp/keys', '/settings/api/keys', '/settings/mcp/keys'] as $path) {
        $this->actingAs($owner)->postJson($host.$path, ['name' => 'Shell', 'access' => 'commands', 'code' => '000000'])->assertJsonValidationErrors('code');
    }

    $this->actingAs($owner)->postJson($host.'/settings/api/keys', ['name' => 'Shell', 'access' => 'commands', 'code' => '123456'])->assertJsonValidationErrors('code');
    $this->actingAs($owner)->postJson($host.'/console/unlock', ['code' => '123456'])->assertJsonValidationErrors('code');
    expect(McpToken::query()->exists())->toBeFalse();

    $this->travel(61)->seconds();
    $this->actingAs($owner)->postJson($host.'/settings/api/keys', ['name' => 'Shell', 'access' => 'commands', 'code' => '123456'])->assertCreated();
});
