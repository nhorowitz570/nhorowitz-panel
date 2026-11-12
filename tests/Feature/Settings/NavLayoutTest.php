<?php

use App\Models\User;
use App\Models\Workspace;

test('the sidebar is the default, and the navigation can move to a top bar and back', function () {
    $user = User::factory()->create();
    expect($user->nav_layout)->toBeNull();

    $this->actingAs($user)
        ->from('/settings/appearance')
        ->put('/settings/appearance/navigation', ['layout' => 'top'])
        ->assertRedirect('/settings/appearance');

    expect($user->refresh()->nav_layout)->toBe('top');

    $this->actingAs($user)
        ->put('/settings/appearance/navigation', ['layout' => 'sidebar'])
        ->assertRedirect();

    expect($user->refresh()->nav_layout)->toBeNull();
});

test('only the known layouts are accepted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/settings/appearance/navigation', ['layout' => 'bottom'])
        ->assertSessionHasErrors('layout');

    expect($user->refresh()->nav_layout)->toBeNull();
});

test('guests cannot change it', function () {
    $this->put('/settings/appearance/navigation', ['layout' => 'sidebar'])
        ->assertRedirect();
});

test('the dashboard settings have an Appearance page', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->attach($user, ['role' => 'owner']);

    $this->actingAs($user)
        ->get($workspace->url().'/settings/appearance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspace/settings/Appearance'));
});
