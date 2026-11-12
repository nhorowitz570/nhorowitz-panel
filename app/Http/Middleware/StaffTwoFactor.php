<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class StaffTwoFactor
{
    public const string MESSAGE = 'The admin pages need two-factor on your account. Set up an authenticator app, then come back.';

    public static function missing(?User $user): bool
    {
        return $user !== null && $user->is_admin && $user->email_verified_at !== null && $user->two_factor_confirmed_at === null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::missing($request->user())) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => self::MESSAGE]);

        return Inertia::location(route('security.edit'));
    }
}
