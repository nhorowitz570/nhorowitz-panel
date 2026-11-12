<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Models\WorkspaceEmailDomain;
use App\Models\WorkspaceInvitation;
use App\Support\Edition;
use Illuminate\Support\Str;

final class SignUps
{
    public const string CLOSED = 'This panel is invite only. Ask its owner to invite you, then sign up with the email the invite went to.';

    public static function open(): bool
    {
        return ! Edition::selfHosted()
            || config('serveros.sign_ups') === 'open'
            || User::query()->doesntExist();
    }

    public static function allows(string $email, ?string $googleDomain = null): bool
    {
        if (self::open()) {
            return true;
        }

        if (WorkspaceInvitation::query()
            ->whereRaw('lower(email) = ?', [Str::lower(trim($email))])
            ->where('expires_at', '>', now())
            ->exists()) {
            return true;
        }

        $workspace = $googleDomain === null ? null : WorkspaceEmailDomain::query()
            ->where('domain', Str::lower($googleDomain))
            ->whereNotNull('verified_at')
            ->first()?->workspace;

        return $workspace !== null && $workspace->domain_join !== 'off' && $workspace->hasGoogleTeamAccess();
    }
}
