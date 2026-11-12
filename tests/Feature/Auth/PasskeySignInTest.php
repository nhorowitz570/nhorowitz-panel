<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Auth\PasskeySignIn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;

function passkeyOnlyMember(): array
{
    $workspace = Workspace::factory()->create(['require_two_factor' => true]);
    $sam = User::factory()->create(['remember_token' => str_repeat('r', 60)]);
    $workspace->members()->attach($sam, ['role' => 'admin']);
    $passkey = Passkey::query()->forceCreate(['user_id' => $sam->id, 'name' => 'Laptop', 'credential_id' => 'abc', 'credential' => []]);

    return [$workspace, $sam, $passkey, 'https://'.$workspace->host().'/machines'];
}

function recallerFor(User $user): string
{
    return $user->id.'|'.$user->remember_token.'|'.$user->getAuthPassword();
}

test('confirming with a passkey counts as two-factor for the rest of the session', function () {
    [$workspace, $sam, $passkey, $machines] = passkeyOnlyMember();

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'password'])->get($machines)
        ->assertRedirect('https://'.$workspace->host().'/user/confirm-password');

    $this->actingAs($sam);
    PasskeyVerified::dispatch($sam, $passkey);
    expect(session(PasskeySignIn::VERIFIED))->toBe($sam->id);
    $this->get($machines)->assertOk();
    expect(session(GoogleTeamAccess::VIA))->toBe('password');
});

test('someone else’s passkey check doesn’t count for the signed-in person', function () {
    [, $sam, , $machines] = passkeyOnlyMember();
    $other = User::factory()->create();
    $theirs = Passkey::query()->forceCreate(['user_id' => $other->id, 'name' => 'Phone', 'credential_id' => 'def', 'credential' => []]);

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'password']);
    PasskeyVerified::dispatch($other, $theirs);

    expect(session(PasskeySignIn::VERIFIED))->toBeNull();
    $this->get($machines)->assertRedirect();
});

test('a passkey sign-in with remember me is remembered for that remember cookie only', function () {
    [, $sam] = passkeyOnlyMember();
    $guard = Auth::guard('web');

    Cookie::queue(Cookie::make($guard->getRecallerName(), recallerFor($sam), 60 * 24 * 400));
    PasskeySignIn::signedIn($sam, 'passkey');
    $kept = json_decode((string) Cookie::queued(PasskeySignIn::COOKIE)->getValue(), true);
    expect($kept)->toBe(['user' => $sam->id, 'recaller' => hash('sha256', recallerFor($sam))])
        ->and(Cookie::queued(PasskeySignIn::COOKIE)->isHttpOnly())->toBeTrue();

    PasskeySignIn::signedIn($sam, 'password');
    expect(Cookie::queued(PasskeySignIn::COOKIE)->getExpiresTime())->toBeLessThan(time());
});

test('when remember me signs someone back in, a passkey sign-in from that device still counts', function () {
    [$workspace, $sam, , $machines] = passkeyOnlyMember();
    $recaller = Auth::guard('web')->getRecallerName();
    $kept = (string) json_encode(['user' => $sam->id, 'recaller' => hash('sha256', recallerFor($sam))]);

    $this->withCookie($recaller, recallerFor($sam))->withCookie(PasskeySignIn::COOKIE, $kept)
        ->get($machines)->assertOk();
    $this->assertAuthenticatedAs($sam);
});

test('remember me alone, or a passkey cookie for another remember cookie, doesn’t count', function () {
    [$workspace, $sam, , $machines] = passkeyOnlyMember();
    $recaller = Auth::guard('web')->getRecallerName();

    $this->withCookie($recaller, recallerFor($sam))->get($machines)
        ->assertRedirect('https://'.$workspace->host().'/user/confirm-password');

    Auth::forgetGuards();
    $this->flushSession();
    $stale = (string) json_encode(['user' => $sam->id, 'recaller' => hash('sha256', 'an|older|cookie')]);
    $this->withCookie($recaller, recallerFor($sam))->withCookie(PasskeySignIn::COOKIE, $stale)->get($machines)
        ->assertRedirect('https://'.$workspace->host().'/user/confirm-password');
});
