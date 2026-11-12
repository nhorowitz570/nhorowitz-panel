<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class ConsoleLock
{
    public const MINUTES = 15;

    private const KEY = 'console.unlocked_until';

    private const WRONG_CODES_PER_MINUTE = 5;

    public function __construct(
        private readonly Session $session,
        private readonly TwoFactorAuthenticationProvider $provider,
    ) {}

    public function requiresSetup(User $user): bool
    {
        return ! $user->hasEnabledTwoFactorAuthentication();
    }

    public function unlockedUntil(): ?Carbon
    {
        $until = $this->session->get(self::KEY);

        return is_int($until) && $until > now()->getTimestamp()
            ? Carbon::createFromTimestamp($until)
            : null;
    }

    public function isUnlocked(User $user): bool
    {
        return ! $this->requiresSetup($user) && $this->unlockedUntil() !== null;
    }

    public function unlock(User $user, string $code): bool
    {
        if ($this->requiresSetup($user) || $user->two_factor_secret === null) {
            return false;
        }

        $attempts = 'console-unlock:'.$user->id;
        if (RateLimiter::tooManyAttempts($attempts, self::WRONG_CODES_PER_MINUTE)) {
            return false;
        }

        if (! $this->provider->verify(decrypt($user->two_factor_secret), $code)) {
            RateLimiter::hit($attempts, 60);

            return false;
        }
        RateLimiter::clear($attempts);
        $this->session->put(self::KEY, now()->addMinutes(self::MINUTES)->getTimestamp());

        return true;
    }

    public function unlockedWith(User $user, mixed $code): bool
    {
        return $this->isUnlocked($user) || (is_string($code) && $code !== '' && $this->unlock($user, $code));
    }

    public function confirm(User $user, ?string $code): ?string
    {
        if ($this->isUnlocked($user)) {
            return null;
        }
        if ($code === null || $code === '') {
            return 'Enter the 6-digit code from your authenticator app.';
        }

        $key = 'console-code:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return 'Too many tries. Wait a minute, then use a fresh code.';
        }
        if (! $this->unlock($user, $code)) {
            RateLimiter::hit($key);

            return 'That code didn’t work. Use the current one from your authenticator app.';
        }

        return null;
    }

    public function lock(): void
    {
        $this->session->forget(self::KEY);
    }
}
