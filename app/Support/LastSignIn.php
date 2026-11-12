<?php

namespace App\Support;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class LastSignIn
{
    public const COOKIE = 'last_sign_in';

    public const DAYS = 400;

    private const METHODS = ['password', 'passkey', 'google', 'github', 'sso'];

    public static function remember(string $method): void
    {
        $method = $method === 'recovery_code' ? 'password' : $method;

        if (in_array($method, self::METHODS, true)) {
            Cookie::queue(self::COOKIE, $method, self::DAYS * 24 * 60);
        }
    }

    public static function fromRequest(Request $request): ?string
    {
        $method = $request->cookie(self::COOKIE);

        return match (true) {
            ! is_string($method) || ! in_array($method, self::METHODS, true) => null,
            in_array($method, ['google', 'github'], true) && ! in_array($method, SocialAccount::enabled(), true) => null,
            default => $method,
        };
    }
}
