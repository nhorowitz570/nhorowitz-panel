<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Whether this session has shown a passkey, for workspaces that require
 * two-factor sign-in. A passkey sign-in says so, and so does confirming with
 * a passkey later. Remember me signs people back in once the session ends,
 * so a passkey sign-in that ticked it leaves a cookie, bound to that remember
 * cookie, saying the sign-in it came from was a passkey.
 */
final class PasskeySignIn
{
    public const string VERIFIED = 'auth.passkey_verified';

    public const string COOKIE = 'passkey_sign_in';

    public static function verified(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $session = $request->session();

        return $session->get(GoogleTeamAccess::VIA) === 'passkey' || $session->get(self::VERIFIED) === $user->id;
    }

    public static function confirmed(Request $request, User $user): void
    {
        if ($request->hasSession() && $request->user()?->is($user)) {
            $request->session()->put(self::VERIFIED, $user->id);
        }
    }

    /** After a sign-in: remember a passkey one on this device, or forget an older one. */
    public static function signedIn(User $user, string $method): void
    {
        $recaller = self::queuedRecaller();
        if ($method !== 'passkey' || $recaller === null) {
            Cookie::queue(Cookie::forget(self::COOKIE, '/', config('session.domain')));

            return;
        }

        $minutes = max(1, (int) ceil(($recaller->getExpiresTime() - time()) / 60));
        Cookie::queue(Cookie::make(
            self::COOKIE,
            (string) json_encode(['user' => $user->id, 'recaller' => hash('sha256', (string) $recaller->getValue())]),
            $minutes,
            '/',
            config('session.domain'),
            config('session.secure'),
            true,
            false,
            config('session.same_site'),
        ));
    }

    /** Signed in again by remember me: carry over a passkey sign-in this device made. */
    public static function recall(Request $request, User $user): void
    {
        $guard = Auth::guard('web');
        if (! $guard instanceof SessionGuard || ! $guard->viaRemember() || ! $request->hasSession()) {
            return;
        }
        $recaller = $request->cookies->get($guard->getRecallerName());
        $kept = json_decode((string) $request->cookies->get(self::COOKIE), true);
        if (! is_string($recaller) || $recaller === '' || ! is_array($kept)
            || ($kept['user'] ?? null) !== $user->id || ! is_string($kept['recaller'] ?? null)
            || ! hash_equals($kept['recaller'], hash('sha256', $recaller))) {
            return;
        }

        $request->session()->put(self::VERIFIED, $user->id);
    }

    private static function queuedRecaller(): ?SymfonyCookie
    {
        $guard = Auth::guard('web');
        if (! $guard instanceof SessionGuard) {
            return null;
        }
        $cookie = Cookie::queued($guard->getRecallerName());

        return $cookie instanceof SymfonyCookie && filled($cookie->getValue()) && $cookie->getExpiresTime() > time() ? $cookie : null;
    }
}
