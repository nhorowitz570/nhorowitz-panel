<?php

use App\Enums\Plan;
use App\Enums\WorkspacePermission;
use App\Mcp\Tools\TeamTools;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Models\WorkspaceJoinRequest;
use Illuminate\Support\Facades\Notification;

function ceilingUrl(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

function ceilingJoin(Workspace $workspace, string $role = 'viewer', ?WorkspaceCustomRole $custom = null): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role, 'custom_role_id' => $custom?->id]);

    return $user;
}

test('someone who only manages members can’t hand out, take away or make more access than they have', function () {
    Notification::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $people = $workspace->customRoles()->create(['name' => 'People', 'permissions' => ['manage-members']]);
    $hal = ceilingJoin($workspace, custom: $people);
    $sam = ceilingJoin($workspace);
    $ada = ceilingJoin($workspace, 'admin');

    $this->actingAs($hal)->post(ceilingUrl($workspace, '/settings/invitations'), ['email' => 'alt@example.com', 'role' => 'admin'])
        ->assertSessionHasErrors('role');
    $this->actingAs($hal)->patch(ceilingUrl($workspace, "/settings/members/{$sam->id}"), ['role' => 'admin'])
        ->assertSessionHasErrors('role');
    $this->actingAs($hal)->patch(ceilingUrl($workspace, "/settings/members/{$ada->id}"), ['role' => 'viewer'])
        ->assertSessionHasErrors('role');
    $this->actingAs($hal)->delete(ceilingUrl($workspace, "/settings/members/{$ada->id}"))
        ->assertSessionHasErrors('member');
    $this->actingAs($hal)->put(ceilingUrl($workspace, "/settings/roles/{$people->id}"), ['name' => 'People', 'permissions' => ['manage-members', 'manage-machines']])
        ->assertSessionHasErrors('permissions');

    expect($workspace->roleOf($sam)?->value)->toBe('viewer')
        ->and($workspace->roleOf($ada)?->value)->toBe('admin')
        ->and($workspace->allows($hal, WorkspacePermission::ManageMachines))->toBeFalse()
        ->and($workspace->invitations()->exists())->toBeFalse();

    $this->actingAs($hal)->post(ceilingUrl($workspace, '/settings/invitations'), ['email' => 'new@example.com', 'role' => 'viewer'])
        ->assertSessionHasNoErrors();
    $this->actingAs($hal)->delete(ceilingUrl($workspace, "/settings/members/{$sam->id}"))
        ->assertSessionHasNoErrors();
    expect($workspace->hasMember($sam))->toBeFalse();
});

test('an admin can’t mint billing access through a custom role, but still runs the team', function () {
    Notification::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $billing = $workspace->customRoles()->create(['name' => 'Billing', 'permissions' => ['manage-billing']]);
    $ada = ceilingJoin($workspace, 'admin');
    $sam = ceilingJoin($workspace);

    $this->actingAs($ada)->post(ceilingUrl($workspace, '/settings/roles'), ['name' => 'Money', 'permissions' => ['manage-billing']])
        ->assertSessionHasErrors('permissions');
    $this->actingAs($ada)->patch(ceilingUrl($workspace, "/settings/members/{$sam->id}"), ['role' => "custom:{$billing->id}"])
        ->assertSessionHasErrors('role');
    $this->actingAs($ada)->put(ceilingUrl($workspace, "/settings/roles/{$billing->id}"), ['name' => 'Billing', 'permissions' => []])
        ->assertSessionHasErrors('permissions');
    $this->actingAs($ada)->delete(ceilingUrl($workspace, "/settings/roles/{$billing->id}"))
        ->assertSessionHasErrors('role');
    expect($workspace->allows($sam, WorkspacePermission::ManageBilling))->toBeFalse()
        ->and($workspace->customRoles()->count())->toBe(1);

    $this->actingAs($ada)->post(ceilingUrl($workspace, '/settings/roles'), ['name' => 'Ops', 'permissions' => ['manage-machines']])
        ->assertSessionHasNoErrors();
    $this->actingAs($ada)->patch(ceilingUrl($workspace, "/settings/members/{$sam->id}"), ['role' => 'admin'])
        ->assertSessionHasNoErrors();
    $this->actingAs($ada)->post(ceilingUrl($workspace, '/settings/invitations'), ['email' => 'new@example.com', 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    $this->actingAs($workspace->owner)->patch(ceilingUrl($workspace, "/settings/members/{$sam->id}"), ['role' => "custom:{$billing->id}"])
        ->assertSessionHasNoErrors();
    expect($workspace->allows($sam, WorkspacePermission::ManageBilling))->toBeTrue();
});

test('join requests and the domain join role stop at the decider’s own access', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $workspace->emailDomains()->create(['domain' => 'acme.com'])->forceFill(['verified_at' => now()])->save();
    $runner = $workspace->customRoles()->create(['name' => 'Runner', 'permissions' => ['manage-members', 'manage-settings']]);
    $hal = ceilingJoin($workspace, custom: $runner);
    $ada = User::factory()->create(['email' => 'ada@acme.com']);
    $request = $workspace->joinRequests()->create(['user_id' => $ada->id, 'domain' => 'acme.com', 'state' => WorkspaceJoinRequest::PENDING]);

    $this->actingAs($hal)->post(ceilingUrl($workspace, "/settings/join-requests/{$request->id}/approve"), ['role' => 'admin'])
        ->assertSessionHasErrors('role');
    $this->actingAs($hal)->put(ceilingUrl($workspace, '/settings/domain-join'), ['mode' => 'auto', 'role' => 'admin'])
        ->assertSessionHasErrors('role');
    expect($workspace->hasMember($ada))->toBeFalse()
        ->and($workspace->fresh()->domain_join)->not->toBe('auto');

    $this->actingAs($hal)->post(ceilingUrl($workspace, "/settings/join-requests/{$request->id}/approve"), ['role' => 'viewer'])
        ->assertSessionHasNoErrors();
    expect($workspace->roleOf($ada)?->value)->toBe('viewer');
});

test('the MCP team tools keep to the same ceiling', function () {
    config()->set('mcp.groups', [TeamTools::class]);
    Notification::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $people = $workspace->customRoles()->create(['name' => 'People', 'permissions' => ['manage-members']]);
    $hal = ceilingJoin($workspace, custom: $people);
    $sam = ceilingJoin($workspace);
    $ada = ceilingJoin($workspace, 'admin');
    $key = mcpKey($workspace, $hal);

    expect(mcpCall($workspace, $key, 'change_member_role', ['member' => $sam->email, 'role' => 'admin'])['text'])->toContain('more access than your own')
        ->and(mcpCall($workspace, $key, 'change_member_role', ['member' => $ada->email, 'role' => 'viewer'])['text'])->toContain('has access you don’t')
        ->and(mcpCall($workspace, $key, 'invite_member', ['email' => 'alt@example.com', 'role' => 'admin'])['text'])->toContain('more access than your own')
        ->and(mcpCall($workspace, $key, 'remove_member', ['member' => $ada->email, 'confirm' => $ada->email])['text'])->toContain('has access you don’t');

    expect($workspace->roleOf($sam)?->value)->toBe('viewer')
        ->and($workspace->roleOf($ada)?->value)->toBe('admin')
        ->and($workspace->invitations()->exists())->toBeFalse();
});
