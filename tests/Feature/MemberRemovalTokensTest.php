<?php

use App\Mcp\Scopes;
use App\Mcp\Tools\TeamTools;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceJoinRequest;

function removalJoin(Workspace $workspace, string $role = 'admin'): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

test('removing someone revokes their keys and app connections for that workspace only', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $sam = removalJoin($workspace);
    $other->members()->attach($sam, ['role' => 'admin']);
    [$mcpKey] = McpToken::issueKey($workspace, $sam, 'Shell', Scopes::ALL);
    [$apiKey, $apiSecret] = McpToken::issueKey($workspace, $sam, 'Bot', Scopes::ALL, McpToken::API);
    [$elsewhere] = McpToken::issueKey($other, $sam, 'Elsewhere', Scopes::ALL);
    [$owners] = McpToken::issueKey($workspace, $workspace->owner, 'Owner', Scopes::ALL);

    $this->actingAs($workspace->owner)->delete('https://'.$workspace->host()."/settings/members/{$sam->id}")->assertSessionHasNoErrors();

    expect($mcpKey->fresh()->revoked_at)->not->toBeNull()
        ->and($apiKey->fresh()->revoked_at)->not->toBeNull()
        ->and($elsewhere->fresh()->revoked_at)->toBeNull()
        ->and($owners->fresh()->revoked_at)->toBeNull();

    $workspace->members()->attach($sam, ['role' => 'admin']);
    $this->withHeaders(['Authorization' => "Bearer {$apiSecret}"])->getJson('/api/v1/machines')->assertUnauthorized();
});

test('remove_member over MCP revokes their access and keeps them from walking back in', function () {
    config()->set('mcp.groups', [TeamTools::class]);
    $workspace = Workspace::factory()->create();
    $sam = removalJoin($workspace);
    [$token] = McpToken::issueKey($workspace, $sam, 'Shell', Scopes::ALL);

    $result = mcpCall($workspace, mcpKey($workspace), 'remove_member', ['member' => $sam->email, 'confirm' => $sam->email]);

    expect($result['error'])->toBeFalse()
        ->and($token->fresh()->revoked_at)->not->toBeNull()
        ->and($workspace->joinRequests()->where('user_id', $sam->id)->where('state', WorkspaceJoinRequest::DENIED)->exists())->toBeTrue();
});
