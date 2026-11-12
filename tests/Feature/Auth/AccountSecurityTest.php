<?php

use App\Http\Middleware\StaffTwoFactor;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\EmailChanged;
use App\Support\Auth\GoogleTeamAccess;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Passkey;

function otherSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Stolen cookie',
        'payload' => base64_encode(json_encode([])),
        'last_activity' => now()->timestamp,
    ]);
}

function inviteOnlyInstall(): void
{
    config(['serveros.edition' => 'self-hosted', 'serveros.sign_ups' => 'closed']);
    DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
}

test('the emailed reset link points at the panel, whatever host the request came in on', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('https://evil.example/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        app('url')->setRequest(Request::create('https://evil.example/forgot-password'));

        return str_starts_with((string) $notification->toMail($user)->actionUrl, 'https://serveros.test/reset-password/');
    });
});

test('the emailed verify link points at the panel, whatever host the request came in on', function () {
    $user = User::factory()->unverified()->create();
    app('url')->setRequest(Request::create('https://evil.example/register'));

    expect((string) (new VerifyEmail)->toMail($user)->actionUrl)->toStartWith('https://serveros.test/email/verify/');
});

test('asking for a reset link says the same thing whether or not the email has an account', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com']);
    $again = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);

    $known->assertSessionHasNoErrors();
    $unknown->assertSessionHasNoErrors();
    $again->assertSessionHasNoErrors();
    expect($unknown->getSession()->get('status'))->toBe($known->getSession()->get('status'))
        ->and($again->getSession()->get('status'))->toBe($known->getSession()->get('status'));
});

test('an email can’t be taken twice by changing its case', function () {
    User::factory()->create(['email' => 'Ada@Example.com']);
    $eve = User::factory()->create();

    $this->actingAs($eve)
        ->patch(route('profile.update'), ['name' => 'Eve', 'email' => 'ada@EXAMPLE.com', 'current_password' => 'password'])
        ->assertSessionHasErrors('email');
    auth()->logout();

    $this->post(route('register.store'), [
        'name' => 'Eve',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(User::query()->whereRaw('lower(email) = ?', ['ada@example.com'])->count())->toBe(1);
});

test('a changed email is kept in lower case, and the same email in another case isn’t a change', function () {
    $user = User::factory()->create(['email' => 'Sam@Example.com']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Sam', 'email' => 'sam@example.com'])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('Sam@Example.com')
        ->and($user->fresh()->email_verified_at)->not->toBeNull();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Sam', 'email' => ' Sam@New.Example.com ', 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('sam@new.example.com');
});

test('changing your email needs your password, and the old address hears about it', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Sam', 'email' => 'eve@example.com'])
        ->assertSessionHasErrors('current_password');
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Sam', 'email' => 'eve@example.com', 'current_password' => 'wrong'])
        ->assertSessionHasErrors('current_password');
    expect($user->fresh()->email)->toBe('sam@example.com');
    Notification::assertNothingSent();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Samantha', 'email' => $user->email])->assertSessionHasNoErrors();
    expect($user->fresh()->name)->toBe('Samantha');

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Sam', 'email' => 'sam@new.example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('sam@new.example.com');
    Notification::assertSentOnDemand(EmailChanged::class, function (EmailChanged $notification, array $channels, AnonymousNotifiable $notifiable) {
        return $notifiable->routes['mail'] === 'sam@example.com' && $notification->newEmail === 'sam@new.example.com';
    });
});

test('someone who only signs in with Google or GitHub can change their email without a password', function () {
    Notification::fake();
    $user = User::factory()->withoutPassword()->create(['email' => 'sam@example.com']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Sam', 'email' => 'sam@new.example.com'])->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('sam@new.example.com');
    Notification::assertSentOnDemand(EmailChanged::class);
});

test('changing your password signs out your other devices', function () {
    $user = User::factory()->create(['remember_token' => 'old-token']);
    otherSession($user, str_repeat('a', 40));
    otherSession(User::factory()->create(), str_repeat('b', 40));

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->pluck('id')->all())->toBe([str_repeat('b', 40)])
        ->and($user->fresh()->remember_token)->not->toBe('old-token');
    $this->assertAuthenticatedAs($user);
});

test('resetting your password signs out every device', function () {
    Notification::fake();
    $user = User::factory()->create(['remember_token' => 'old-token']);
    otherSession($user, str_repeat('a', 40));

    $this->post('/forgot-password', ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe('old-token');
});

test('a passkey only counts for required two-factor when this session signed in with it', function () {
    $workspace = Workspace::factory()->create(['require_two_factor' => true]);
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'admin']);
    Passkey::query()->forceCreate(['user_id' => $sam->id, 'name' => 'Laptop', 'credential_id' => 'abc', 'credential' => []]);
    $machines = 'https://'.$workspace->host().'/machines';

    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'password'])->get($machines)
        ->assertRedirect('https://'.$workspace->host().'/user/confirm-password')
        ->assertSessionHas('url.intended', $machines);
    $this->actingAs($sam)->withSession([GoogleTeamAccess::VIA => 'passkey'])->get($machines)->assertOk();

    expect($workspace->satisfiesTwoFactor($sam))->toBeTrue();
});

test('a self-hosted panel is invite only once it has an owner', function () {
    inviteOnlyInstall();
    $workspace = Workspace::factory()->create();
    $signUp = fn (string $email) => $this->post(route('register.store'), [
        'name' => 'Grace Hopper',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $signUp('stranger@example.com')->assertSessionHasErrors('email');
    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();
    $this->get(route('register'))->assertInertia(fn ($page) => $page->where('inviteOnly', true));

    WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'grace@example.com', 'role' => 'admin']);
    $signUp('Grace@Example.com')->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'grace@example.com')->exists())->toBeTrue();

    auth()->logout();
    WorkspaceInvitation::query()->update(['expires_at' => now()->subDay()]);
    $signUp('late@example.com')->assertSessionHasErrors('email');

    config(['serveros.sign_ups' => 'open']);
    $signUp('open@example.com')->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'open@example.com')->exists())->toBeTrue();
});

test('serveros.com sign-ups stay open whatever the setting says', function () {
    config(['serveros.sign_ups' => 'closed']);
    Workspace::factory()->create();

    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
});

test('new two-factor secrets are 160 bits', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertSessionHasNoErrors();

    expect(strlen(decrypt($user->fresh()->two_factor_secret)))->toBe(32);
});

test('staff need two-factor on for the admin pages and Pulse, and are told how to get it', function () {
    Route::middleware(['web', 'auth', 'verified', StaffTwoFactor::class, 'can:viewAdmin'])->get('admin/test-page', fn () => 'Admin');
    $admin = User::factory()->admin()->create();
    $admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    expect(Gate::forUser($admin)->allows('viewAdmin'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewPulse'))->toBeFalse()
        ->and(config('pulse.middleware'))->toContain(StaffTwoFactor::class);

    $this->actingAs($admin)->get('/admin/test-page')
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('inertia.flash_data.toast.message', StaffTwoFactor::MESSAGE);
    $this->actingAs($admin)->getJson('/admin/test-page')->assertForbidden()->assertJson(['message' => StaffTwoFactor::MESSAGE]);
    $this->actingAs($admin)->get('/settings/profile')->assertInertia(fn ($page) => $page->where('staff.admin', true));

    $admin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    expect(Gate::forUser($admin->fresh())->allows('viewAdmin'))->toBeTrue();
    $this->actingAs($admin->fresh())->get('/admin/test-page')->assertOk();
});

test('an older account that shares its email with another apart from case can still save its name', function () {
    $older = User::factory()->create(['email' => 'Sam@Example.com']);
    User::factory()->create(['email' => 'sam@example.com']);

    $this->actingAs($older)->patch(route('profile.update'), ['name' => 'Samantha', 'email' => 'Sam@Example.com'])->assertSessionHasNoErrors();

    expect($older->fresh()->name)->toBe('Samantha')
        ->and($older->fresh()->email)->toBe('Sam@Example.com');
});
