<?php

use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Auth\GoogleTeamAccess;

test('creating a workspace can move machines in, with their monitors and backups', function () {
    $from = Workspace::factory()->create();
    $owner = $from->owner;
    $web = Machine::factory()->for($from)->create(['name' => 'web']);
    $db = Machine::factory()->for($from)->create(['name' => 'db']);

    $destination = BackupDestination::query()->forceCreate(['workspace_id' => $from->id, 'name' => 'S3', 'type' => 's3', 'settings' => ['bucket' => 'b'], 'status' => 'ok']);
    $web->forceFill(['backup_destination_id' => $destination->id])->save();
    $db->forceFill(['backup_destination_id' => $destination->id])->save();
    $monitor = Monitor::query()->forceCreate(['workspace_id' => $from->id, 'machine_id' => $web->id, 'name' => 'site', 'type' => 'http', 'url' => 'https://example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    $page = WorkspaceStatusPage::query()->forceCreate(['workspace_id' => $from->id, 'slug' => 'from-status', 'title' => 'Status', 'machines' => [$web->id, $db->id]]);

    $this->actingAs($owner)->getJson('/workspaces/movable-machines')
        ->assertOk()
        ->assertJsonPath('workspaces.0.machines.1.name', 'web');

    $this->actingAs($owner)->post('/workspaces', ['name' => 'Client B', 'machines' => [$web->id]])->assertRedirect();

    $to = Workspace::query()->where('name', 'Client B')->sole();
    expect($web->refresh()->workspace_id)->toBe($to->id)
        ->and($db->refresh()->workspace_id)->toBe($from->id)
        ->and($monitor->refresh()->workspace_id)->toBe($to->id)
        ->and($page->refresh()->machines)->toBe([$db->id])
        ->and($db->backup_destination_id)->toBe($destination->id)
        ->and(BackupDestination::query()->find($web->backup_destination_id)->workspace_id)->toBe($to->id)
        ->and(Activity::query()->where('workspace_id', $to->id)->where('action', 'machine.move_in')->exists())->toBeTrue();
});

test('machines from a workspace you cannot manage machines in are refused', function () {
    $theirs = Machine::factory()->for(Workspace::factory()->create())->create();
    $user = Workspace::factory()->create()->owner;

    $this->actingAs($user)->post('/workspaces', ['name' => 'Mine', 'machines' => [$theirs->id]])->assertSessionHasErrors('machines');
    expect(Workspace::query()->where('name', 'Mine')->exists())->toBeFalse();
});

test('a new workspace takes no more machines than its trial allows', function () {
    $from = Workspace::factory()->create(['machine_limit_override' => -1]);
    $machines = Machine::factory()->for($from)->count(Workspace::TRIAL_PLAN->machineLimit() + 1)->create();

    $this->actingAs($from->owner)->post('/workspaces', ['name' => 'Too many', 'machines' => $machines->modelKeys()])->assertSessionHasErrors('machines');
});

test('only an owner can move a workspace’s machines out of it', function (WorkspaceRole $role) {
    $from = Workspace::factory()->create();
    $machine = Machine::factory()->for($from)->create();
    $user = User::factory()->create();
    $from->members()->attach($user, ['role' => $role->value]);

    $this->actingAs($user)->getJson('/workspaces/movable-machines')->assertOk()->assertJsonCount(0, 'workspaces');
    $this->actingAs($user)->post('/workspaces', ['name' => 'Mine', 'machines' => [$machine->id]])->assertSessionHasErrors('machines');

    expect($machine->refresh()->workspace_id)->toBe($from->id)
        ->and(Workspace::query()->where('name', 'Mine')->exists())->toBeFalse();
})->with([WorkspaceRole::Admin, WorkspaceRole::Member, WorkspaceRole::Viewer]);

test('moving machines out needs the workspace’s two-factor requirement met', function () {
    $from = Workspace::factory()->create(['require_two_factor' => true]);
    $machine = Machine::factory()->for($from)->create();

    $this->actingAs($from->owner)->getJson('/workspaces/movable-machines')->assertOk()->assertJsonCount(0, 'workspaces');
    $this->actingAs($from->owner)->post('/workspaces', ['name' => 'Mine', 'machines' => [$machine->id]])->assertSessionHasErrors('machines');
    expect($machine->refresh()->workspace_id)->toBe($from->id);

    $from->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($from->owner)->post('/workspaces', ['name' => 'Mine', 'machines' => [$machine->id]])->assertRedirect();
    expect($machine->refresh()->workspace_id)->not->toBe($from->id);
});

test('a co-owner has to sign in with Google to move machines out of a workspace that requires it', function () {
    $from = Workspace::factory()->create(['require_google_sign_in' => true]);
    $from->emailDomains()->create(['domain' => 'acme.com']);
    $machine = Machine::factory()->for($from)->create();
    $coOwner = User::factory()->create(['email' => 'sam@acme.com']);
    $from->members()->attach($coOwner, ['role' => WorkspaceRole::Owner->value]);

    $this->actingAs($coOwner)->post('/workspaces', ['name' => 'Mine', 'machines' => [$machine->id]])->assertSessionHasErrors('machines');
    expect($machine->refresh()->workspace_id)->toBe($from->id);

    $this->actingAs($coOwner)->withSession([GoogleTeamAccess::VIA => 'google', GoogleTeamAccess::HD => 'acme.com'])
        ->post('/workspaces', ['name' => 'Mine', 'machines' => [$machine->id]])->assertRedirect();
    expect($machine->refresh()->workspace_id)->not->toBe($from->id);
});
