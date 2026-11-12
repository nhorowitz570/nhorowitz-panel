<?php

namespace App\Listeners;

use App\Models\AccountEvent;
use App\Models\User;
use App\Support\Admin\Impersonation;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Auth\PasskeySignIn;
use App\Support\Extensions\Extensions;
use App\Support\LastSignIn;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

class RecordAccountEvents
{
    public const string VIA = 'login.via';

    public const array VENDOR_ROUTES = [
        'login.store' => 'signin, signin.failed, signin.locked_out (from the login rate limiter)',
        'two-factor.login.store' => 'signin, signin.failed',
        'passkey.login' => 'signin',
        'logout' => 'signout',
        'register.store' => 'account.created',
        'password.email' => 'password.reset_requested',
        'password.update' => 'password.reset',
        'two-factor.confirm' => 'two_factor.on',
        'two-factor.disable' => 'two_factor.off',
        'two-factor.regenerate-recovery-codes' => 'two_factor.recovery_codes',
        'passkey.store' => 'passkey.added',
        'passkey.destroy' => 'passkey.removed',
        'two-factor.enable' => 'nothing: it only counts once confirmed (two_factor.on)',
        'verification.send' => 'nothing: sends the email again',
        'password.confirm.store' => 'nothing: confirms who you are for the next step',
        'passkey.confirm' => 'nothing: confirms who you are for the next step',
    ];

    public const array SIGN_IN_ROUTES = [
        'login.store' => 'password',
        'two-factor.login.store' => 'password',
        'passkey.login' => 'passkey',
        'oauth.callback' => 'social',
        'oauth.credential' => 'social',
        'register.store' => 'password',
    ];

    public static function signInMethod(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }

        foreach (app(Extensions::class)->signInHooks() as $hooks) {
            if (isset($hooks->signInRoutes()[$route])) {
                return $hooks->signInRoutes()[$route];
            }
        }

        return self::SIGN_IN_ROUTES[$route] ?? null;
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            CurrentDeviceLogout::class => 'logout',
            Failed::class => 'failed',
            TwoFactorAuthenticationFailed::class => 'wrongCode',
            TwoFactorAuthenticationChallenged::class => 'challenged',
            PasswordReset::class => 'passwordReset',
            PasswordResetLinkSent::class => 'resetLinkSent',
            Verified::class => 'verified',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorOn',
            TwoFactorAuthenticationDisabled::class => 'twoFactorOff',
            RecoveryCodesGenerated::class => 'recoveryCodes',
            PasskeyRegistered::class => 'passkeyAdded',
            PasskeyDeleted::class => 'passkeyRemoved',
            PasskeyVerified::class => 'passkeyVerified',
        ];
    }

    public function login(Login $event): void
    {
        $request = request();
        $route = $request->route()?->getName();
        $method = self::signInMethod($route);
        if (! $event->user instanceof User) {
            return;
        }
        if ($method === null) {
            PasskeySignIn::recall($request, $event->user);

            return;
        }

        $firstStep = $method;
        $twoFactor = false;
        if ($route === 'oauth.callback' || $route === 'oauth.credential') {
            $method = $firstStep = (string) $request->route('provider');
        }
        if ($route === 'two-factor.login.store') {
            $via = $request->hasSession() ? $request->session()->pull(self::VIA) : null;
            $firstStep = is_string($via) ? $via : 'password';
            $method = filled($request->input('recovery_code')) ? 'recovery_code' : $firstStep;
            $twoFactor = $method !== 'recovery_code';
        }

        LastSignIn::remember($method);

        app(GoogleTeamAccess::class)->signedIn($request, $event->user, $firstStep);
        PasskeySignIn::signedIn($event->user, $firstStep);

        $event->user->wasRecentlyCreated
            ? AccountEvent::record($event->user, 'account.created', [], $method)
            : AccountEvent::record($event->user, 'signin', $twoFactor ? ['two_factor' => true] : [], $method);
    }

    public function challenged(TwoFactorAuthenticationChallenged $event): void
    {
        if (request()->hasSession()) {
            request()->session()->forget([self::VIA, GoogleTeamAccess::PENDING_HD]);
            foreach (app(Extensions::class)->signInHooks() as $hooks) {
                $hooks->challenged(request());
            }
        }
    }

    public function logout(Logout|CurrentDeviceLogout $event): void
    {
        $request = request();
        if ($event->user instanceof User && $request->route()?->getName() === 'logout' && ! Impersonation::active($request)) {
            AccountEvent::record($event->user, 'signout');
        }
    }

    public function failed(Failed $event): void
    {
        if ($event->user instanceof User) {
            AccountEvent::record($event->user, 'signin.failed', ['reason' => 'password']);
        }
    }

    public function wrongCode(TwoFactorAuthenticationFailed $event): void
    {
        AccountEvent::record($event->user, 'signin.failed', ['reason' => 'code']);
    }

    public function passwordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            AccountEvent::record($event->user, 'password.reset');
        }
    }

    public function resetLinkSent(PasswordResetLinkSent $event): void
    {
        if ($event->user instanceof User && request()->route()?->getName() === 'password.email') {
            AccountEvent::record($event->user, 'password.reset_requested');
        }
    }

    public function verified(Verified $event): void
    {
        if ($event->user instanceof User) {
            AccountEvent::record($event->user, 'email.verified');
        }
    }

    public function twoFactorOn(TwoFactorAuthenticationConfirmed $event): void
    {
        AccountEvent::record($event->user, 'two_factor.on');
    }

    public function twoFactorOff(TwoFactorAuthenticationDisabled $event): void
    {
        if (($event->user->getPrevious()['two_factor_confirmed_at'] ?? null) !== null) {
            AccountEvent::record($event->user, 'two_factor.off');
        }
    }

    public function recoveryCodes(RecoveryCodesGenerated $event): void
    {
        AccountEvent::record($event->user, 'two_factor.recovery_codes');
    }

    public function passkeyVerified(PasskeyVerified $event): void
    {
        if ($event->user instanceof User) {
            PasskeySignIn::confirmed(request(), $event->user);
        }
    }

    public function passkeyAdded(PasskeyRegistered $event): void
    {
        if ($event->user instanceof User) {
            AccountEvent::record($event->user, 'passkey.added', ['name' => $event->passkey->name]);
        }
    }

    public function passkeyRemoved(PasskeyDeleted $event): void
    {
        if ($event->user instanceof User) {
            AccountEvent::record($event->user, 'passkey.removed', ['name' => $event->passkey->name]);
        }
    }
}
