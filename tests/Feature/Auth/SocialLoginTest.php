<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;

beforeEach(function () {
    config()->set('serveros.coming_soon', false);
    foreach (['google', 'github'] as $provider) {
        config()->set("services.{$provider}.client_id", 'id');
        config()->set("services.{$provider}.client_secret", 'secret');
    }
    config()->set('services.google.identity_button', true);
});

function providerUser(string $email, string $id = '123', bool $verified = true, ?string $avatar = null): ProviderUser
{
    return (new ProviderUser)
        ->setRaw(['email_verified' => $verified])
        ->map(['id' => $id, 'name' => 'Ada Lovelace', 'nickname' => 'ada', 'email' => $email, 'avatar' => $avatar]);
}

function returnsFrom(string $provider, ProviderUser $user): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($user);
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with($provider)->andReturn($driver);

    Socialite::swap($socialite);
}

test('the buttons only show for providers with keys', function () {
    config()->set('services.github.client_id', null);

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('oauthProviders', ['google']));
});

test('a provider without keys is turned away', function () {
    config()->set('services.github.client_id', null);

    $this->get('/auth/github/redirect')->assertRedirect(route('login'));
});

test('a new person gets an account and a workspace', function () {
    returnsFrom('google', providerUser('ada@example.com'));

    $this->get('/auth/google/callback')->assertRedirect();

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($user->ownedWorkspaces()->count())->toBe(1)
        ->and($user->socialAccounts()->where('provider', 'google')->value('provider_id'))->toBe('123');
});

test('an existing account with the same email is linked, then found by the link', function () {
    $user = User::factory()->create(['email' => 'Ada@Example.com']);
    returnsFrom('github', providerUser('ada@example.com', id: '42'));

    $this->get('/auth/github/callback');
    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::count())->toBe(1);

    auth()->logout();
    returnsFrom('github', providerUser('new@example.com', id: '42'));
    $this->get('/auth/github/callback');

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

test('an unverified Google email is refused', function () {
    returnsFrom('google', providerUser('ada@example.com', verified: false));

    $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('two-factor accounts still get the code prompt', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    returnsFrom('google', providerUser('ada@example.com'));

    $this->get('/auth/google/callback')
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('suspended accounts are turned away', function () {
    User::factory()->create(['email' => 'ada@example.com'])->forceFill(['suspended_at' => now()])->save();
    returnsFrom('google', providerUser('ada@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');
    $this->assertGuest();
});

test('their Google or GitHub picture becomes their profile picture', function () {
    Storage::fake('public');
    Http::fake(['lh3.googleusercontent.com/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])]);
    returnsFrom('google', providerUser('ada@example.com', avatar: 'https://lh3.googleusercontent.com/a/abc=s96-c'));

    $this->get('/auth/google/callback');

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->avatar_path)->toEndWith('.png');
    Storage::disk('public')->assertExists($user->avatar_path);
    Http::assertSent(fn ($request) => $request->url() === 'https://lh3.googleusercontent.com/a/abc=s256-c');
});

test('a picture they chose is kept, and other hosts are never fetched', function () {
    Storage::fake('public');
    Http::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->forceFill(['avatar_path' => 'avatars/mine.png'])->save();
    returnsFrom('github', providerUser('ada@example.com', avatar: 'https://avatars.githubusercontent.com/u/1?v=4'));

    $this->get('/auth/github/callback');
    expect($user->fresh()->avatar_path)->toBe('avatars/mine.png');

    $other = User::factory()->create(['email' => 'eve@example.com']);
    returnsFrom('github', providerUser('eve@example.com', id: '9', avatar: 'https://169.254.169.254/latest'));
    auth()->logout();
    $this->get('/auth/github/callback');

    expect($other->fresh()->avatar_path)->toBeNull();
    Http::assertNothingSent();
});

test('a picture that fails to download doesn\'t stop the sign-in', function () {
    Storage::fake('public');
    Http::fake(['*' => Http::response('nope', 500)]);
    returnsFrom('github', providerUser('ada@example.com', avatar: 'https://avatars.githubusercontent.com/u/1?v=4'));

    $this->get('/auth/github/callback');

    $this->assertAuthenticated();
    expect(User::firstOrFail()->avatar_path)->toBeNull();
});

test('a callback without a code is a retry, and only a failed exchange with one is reported', function () {
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andThrow(new RuntimeException('token exchange failed'));
    $socialite = Mockery::mock(Factory::class);
    $socialite->shouldReceive('driver')->with('google')->andReturn($driver);
    Socialite::swap($socialite);
    Exceptions::fake();

    $this->get('/auth/google/callback?error=access_denied')->assertRedirect(route('login'))->assertSessionHas('oauth_error');
    Exceptions::assertNothingReported();

    $this->get('/auth/google/callback?code=abc')->assertRedirect(route('login'));
    Exceptions::assertReported(RuntimeException::class);
    $this->assertGuest();
});

test('an account whose email was never confirmed isn’t linked or confirmed by a social sign-in', function () {
    $squatter = User::factory()->unverified()->create(['email' => 'Ada@Example.com']);
    returnsFrom('google', providerUser('ada@example.com'));

    $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');

    $this->assertGuest();
    expect($squatter->fresh()->email_verified_at)->toBeNull()
        ->and(SocialAccount::count())->toBe(0)
        ->and(User::count())->toBe(1);
});

test('on an invite-only self-hosted panel, a social sign-in only makes an account for someone invited', function () {
    config(['serveros.edition' => 'self-hosted', 'serveros.sign_ups' => 'closed']);
    DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
    $workspace = Workspace::factory()->create();

    returnsFrom('github', providerUser('stranger@example.com', id: '7'));
    $this->get('/auth/github/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');
    $this->assertGuest();
    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();

    WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'grace@example.com', 'role' => 'admin']);
    returnsFrom('github', providerUser('grace@example.com', id: '8'));
    $this->get('/auth/github/callback');
    $this->assertAuthenticatedAs(User::query()->where('email', 'grace@example.com')->sole());
});

test('on an invite-only self-hosted panel, Google sign-in still lets in people from a domain a workspace lets join', function () {
    config(['serveros.edition' => 'self-hosted', 'serveros.sign_ups' => 'closed']);
    DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['domain_join' => 'auto'])->save();
    $workspace->emailDomains()->create(['domain' => 'acme.com'])->forceFill(['verified_at' => now()])->save();
    $teammate = fn (string $email, string $id) => (new ProviderUser)
        ->setRaw(['email_verified' => true, 'hd' => 'acme.com'])
        ->map(['id' => $id, 'name' => 'Grace Hopper', 'nickname' => 'grace', 'email' => $email, 'avatar' => null]);

    returnsFrom('google', $teammate('grace@acme.com', 'g-1'));
    $this->get('/auth/google/callback');
    $this->assertAuthenticatedAs($grace = User::query()->where('email', 'grace@acme.com')->sole());
    expect($workspace->hasMember($grace))->toBeTrue();

    auth()->logout();
    $workspace->forceFill(['domain_join' => 'off'])->save();
    returnsFrom('google', $teammate('alan@acme.com', 'g-2'));
    $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');
    $this->assertGuest();
});

function googleKeys(): array
{
    static $pair = null;
    $pair ??= (function () {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'private' => $key,
            'jwk' => ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])],
        ];
    })();

    return $pair;
}

function googleCredential(array $claims = [], ?string $kid = 'test-key'): string
{
    Cache::forget('google.id_token.keys');
    Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [googleKeys()['jwk']]])]);

    return JWT::encode([
        'iss' => 'https://accounts.google.com',
        'aud' => 'id',
        'sub' => '123',
        'email' => 'ada@example.com',
        'email_verified' => true,
        'name' => 'Ada Lovelace',
        'iat' => now()->timestamp,
        'exp' => now()->addHour()->timestamp,
        ...$claims,
    ], googleKeys()['private'], 'RS256', $kid);
}

test('the login page gets the Google client id only when Google and its button are set up', function () {
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('googleClientId', 'id'));

    config()->set('services.google.identity_button', false);
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('googleClientId', null));

    config()->set('services.google.identity_button', true);
    config()->set('services.google.client_secret', null);
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('googleClientId', null));
});

test('the Google button stays off the sign-in page on a workspace address, which Google has no origin for', function () {
    $this->get('https://acme.'.config('app.domain').'/login')->assertInertia(fn ($page) => $page->where('googleClientId', null));
    $this->get('https://'.config('app.domain').'/login')->assertInertia(fn ($page) => $page->where('googleClientId', 'id'));
});

test('a Google credential is refused while the button is off', function () {
    config()->set('services.google.identity_button', false);

    $this->post('/auth/google/credential', ['credential' => googleCredential()])
        ->assertRedirect(route('login'))
        ->assertSessionHas('oauth_error');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a Google credential signs a new person in and makes their account', function () {
    $this->post('/auth/google/credential', ['credential' => googleCredential()])->assertRedirect();

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->ownedWorkspaces()->count())->toBe(1)
        ->and($user->socialAccounts()->where('provider', 'google')->value('provider_id'))->toBe('123');
});

test('a Google credential finds the account the redirect sign-in made', function () {
    returnsFrom('google', providerUser('ada@example.com'));
    $this->get('/auth/google/callback');
    $user = User::where('email', 'ada@example.com')->firstOrFail();
    $this->post(route('logout'));

    $this->post('/auth/google/credential', ['credential' => googleCredential()])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1)->and(SocialAccount::count())->toBe(1);
});

test('a Google credential made for another app is refused', function () {
    $this->post('/auth/google/credential', ['credential' => googleCredential(['aud' => 'someone-else'])])
        ->assertRedirect(route('login'))
        ->assertSessionHas('oauth_error');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('an expired, unsigned, wrongly issued or unverified Google credential is refused', function (Closure $credential) {
    $this->post('/auth/google/credential', ['credential' => $credential()])
        ->assertRedirect(route('login'))
        ->assertSessionHas('oauth_error');

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'expired' => [fn () => googleCredential(['exp' => now()->subMinute()->timestamp])],
    'wrong issuer' => [fn () => googleCredential(['iss' => 'https://example.com'])],
    'unknown key' => [fn () => googleCredential(kid: 'another-key')],
    'signed by someone else' => [function () {
        $token = googleCredential();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        return JWT::encode(['iss' => 'https://accounts.google.com', 'aud' => 'id', 'sub' => '123', 'email' => 'ada@example.com', 'email_verified' => true, 'exp' => now()->addHour()->timestamp], $other, 'RS256', 'test-key');
    }],
    'unsigned' => [function () {
        googleCredential();
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'none', 'kid' => 'test-key']).'.'.$encode(['iss' => 'https://accounts.google.com', 'aud' => 'id', 'sub' => '123', 'email' => 'ada@example.com', 'email_verified' => true, 'exp' => now()->addHour()->timestamp]).'.';
    }],
    'unverified email' => [fn () => googleCredential(['email_verified' => false])],
    'not a token' => [fn () => 'nope'],
]);

test('a Google credential still gets the two-factor prompt', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);

    $this->post('/auth/google/credential', ['credential' => googleCredential()])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('GitHub has no credential sign-in', function () {
    $this->post('/auth/github/credential', ['credential' => 'x'])->assertNotFound();
});
