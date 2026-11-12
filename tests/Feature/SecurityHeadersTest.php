<?php

use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

it('keeps panel pages from being framed by other sites', function () {
    $workspace = Workspace::factory()->create();

    $response = $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/machines')->assertOk();

    $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('sends HSTS only over https on an https panel', function () {
    config(['app.url' => 'https://serveros.test']);
    $this->get('https://serveros.test/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    $this->get('http://serveros.test/login')->assertHeaderMissing('Strict-Transport-Security');

    config(['app.url' => 'http://panel.local']);
    $this->get('https://panel.local/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('leaves a page that sets its own framing rules alone', function () {
    Route::middleware('web')->get('/_embeddable', fn () => response('ok')->header('Content-Security-Policy', 'frame-ancestors *'));
    Route::middleware('web')->get('/_frameless', fn () => response('ok')->withHeaders(['X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer']));

    $this->get('/_embeddable')->assertHeader('Content-Security-Policy', 'frame-ancestors *')->assertHeaderMissing('X-Frame-Options');
    $this->get('/_frameless')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
});
