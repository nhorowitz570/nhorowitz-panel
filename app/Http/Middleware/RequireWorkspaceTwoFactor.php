<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspaceTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        $user = $request->user();

        if (! $workspace instanceof Workspace
            || $user === null
            || $request->routeIs('workspace.settings.security')
            || $workspace->satisfiesTwoFactor($user, $request)) {
            return $next($request);
        }

        $passkeys = $user->passkeys()->exists();
        $message = $passkeys
            ? "{$workspace->name} requires two-factor sign-in. Confirm it’s you with your passkey to carry on, or set up an authenticator app."
            : "{$workspace->name} requires two-factor sign-in. Set up an authenticator app or a passkey to continue.";

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        // A passkey shown now counts (PasskeySignIn), and confirming brings
        // them back to the page they asked for.
        if ($passkeys) {
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return Inertia::location(url('/user/confirm-password'));
        }

        return Inertia::location(route('workspace.settings.security', $workspace->slug));
    }
}
