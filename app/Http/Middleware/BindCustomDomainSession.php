<?php

namespace App\Http\Middleware;

use App\Support\Audit\AuditContext;
use App\Support\Domains\CustomDomains;
use App\Support\Extensions\Extensions;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BindCustomDomainSession
{
    public const string KEY = 'custom_domain_host';

    public const array WORKSPACE_ACCOUNT_ROUTES = [
        'workspace.settings.profile',
        'workspace.settings.security',
        'workspace.settings.api',
        'workspace.settings.api.keys.store',
        'workspace.settings.mcp',
        'workspace.settings.mcp.keys.store',
    ];

    private const array ACCOUNT_ROUTES = [
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'profile.avatar.update',
        'profile.avatar.destroy',
        'security.edit',
        'user-password.update',
        'sessions.destroy',
        'sessions.destroy-others',
        'social.connect',
        'social.disconnect',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'passkey.store',
        'passkey.registration-options',
        'passkey.destroy',
        'mcp.oauth.authorize',
        'mcp.oauth.approve',
        'workspaces.index',
        'workspaces.store',
        'workspaces.movable-machines',
        'invitations.show',
        'invitations.accept',
        'join-requests.show',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $host = self::customHost($request);
        $bound = $request->session()->get(self::KEY);
        if (is_string($bound) && $bound !== $host) {
            $this->signOut($request);
        }

        if ($host === null) {
            return $next($request);
        }

        $route = $request->route()?->getName();
        if (in_array($route, [...self::ACCOUNT_ROUTES, ...app(Extensions::class)->accountRoutes(), ...self::WORKSPACE_ACCOUNT_ROUTES], true)) {
            $slug = CustomDomains::resolve($host)['slug'] ?? '';
            $base = $slug !== '' && in_array($route, self::WORKSPACE_ACCOUNT_ROUTES, true)
                ? 'https://'.$slug.'.'.config('app.domain')
                : rtrim((string) config('app.url'), '/');
            $url = $base.$request->getRequestUri();
            AuditContext::nothingChanged();

            return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
        }

        if ($request->has('remember')) {
            $request->merge(['remember' => false]);
        }

        $response = $next($request);
        if (Auth::guard('web')->check()) {
            $request->session()->put(self::KEY, $host);
        }

        return $response;
    }

    private function signOut(Request $request): void
    {
        $guard = Auth::guard('web');
        if (! $guard->check()) {
            $request->session()->forget(self::KEY);

            return;
        }

        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private static function customHost(Request $request): ?string
    {
        $host = $request->attributes->get('custom_domain');
        $ours = strtolower((string) config('app.domain'));

        return is_string($host) && $host !== $ours && ! str_ends_with($host, ".{$ours}") ? $host : null;
    }
}
