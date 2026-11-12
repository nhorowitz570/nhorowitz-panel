<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Admin\Impersonation;
use App\Support\Auth\GoogleTeamAccess;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspaceGoogleSignIn
{
    public const string SWITCH_ROUTE = 'workspace.google-sign-in';

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        $user = $request->user();

        if (! $workspace instanceof Workspace
            || $user === null
            || $request->routeIs(self::SWITCH_ROUTE)
            || Impersonation::active($request)) {
            return $next($request);
        }

        $session = $request->hasSession() ? $request->session() : null;
        $via = $session?->get(GoogleTeamAccess::VIA);
        $hd = $session?->get(GoogleTeamAccess::HD);
        if ($workspace->satisfiesGoogleSignIn($user, is_string($via) ? $via : null, is_string($hd) ? $hd : null)) {
            return $next($request);
        }

        $domains = $workspace->emailDomains()->whereNotNull('verified_at')->pluck('domain')->all()
            ?: $workspace->emailDomains()->pluck('domain')->all();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => "{$workspace->name} asks you to sign in with Google using your company account.",
                'code' => 'google_sign_in_required',
            ], 403);
        }

        return Inertia::render('auth/GoogleSignInRequired', [
            'workspace' => $workspace->name,
            'domains' => $domains,
            'email' => $user->email,
            'switchUrl' => route(self::SWITCH_ROUTE, ['workspace' => $workspace->slug]),
        ])->toResponse($request);
    }
}
