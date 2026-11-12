<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Admin\Impersonation;
use App\Support\Brand;
use App\Support\Docs\DocsUrl;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\MarketingSite;
use App\Support\GitHub\GitHubApp;
use App\Support\SetupGuide;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    public function shouldServerRender(Request $request): bool
    {
        return $this->isMarketing($request) && app()->getLocale() === 'en';
    }

    public function rootView(Request $request): string
    {
        $site = $this->marketingSite($request);

        return $site === null ? $this->rootView : $site->rootView;
    }

    protected function isMarketing(Request $request): bool
    {
        return $this->marketingSite($request) !== null;
    }

    private function marketingSite(Request $request): ?MarketingSite
    {
        $site = app(Extensions::class)->marketingSite();

        return $site !== null && $site->routes !== [] && $request->routeIs(...$site->routes) ? $site : null;
    }

    public function version(Request $request): ?string
    {
        $version = parent::version($request);

        return $this->isMarketing($request) ? 'marketing-'.$version : $version;
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $shared = [
            ...parent::share($request),
            'name' => config('app.name'),
            'celebrate' => fn () => $request->routeIs('workspace.*') && ! $request->routeIs('workspace.welcome.*') && $request->user()?->hasVerifiedEmail()
                ? (bool) $request->session()->pull('celebrate_trial', false)
                : false,
            'appUrl' => rtrim((string) config('app.url'), '/'),
            'siteOrigin' => $this->siteOrigin($request),
            'openSource' => (bool) config('serveros.open_source'),
            'statusUrl' => filled(config('serveros.status_page')) ? 'https://status.'.config('app.domain') : null,
            'brand' => Brand::shared(),
            'docsUrl' => DocsUrl::home(),
            'feedbackUrl' => Router::has('feedback.index') ? rtrim(route('feedback.index'), '/') : null,
            'auth' => [
                'user' => $request->user(),
            ],
            'workspaces' => fn () => $request->user()?->workspaces()
                ->orderBy('name')
                ->get()
                ->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'host' => $workspace->host(),
                    'url' => $workspace->url(),
                    'switchUrl' => route('workspace.switch', ['workspace' => $workspace->slug]),
                    'logoUrl' => $workspace->logoUrl(),
                    'pinnedAt' => $workspace->pivot->pinned_at === null ? null : (string) $workspace->pivot->pinned_at,
                ])
                ->all() ?? [],
            'extensions' => function () use ($request): ?array {
                $workspace = self::workspacePage($request);

                if ($workspace === null) {
                    return null;
                }

                $extensions = app(Extensions::class);

                return [
                    'nav' => $extensions->navFor($workspace, $request->user()),
                    'settings' => $extensions->settingsFor($workspace, $request->user()),
                ];
            },
            'upgrade' => function () use ($request): ?array {
                $workspace = self::workspacePage($request);

                return $workspace === null ? null : app(Extensions::class)->upgradeFor($workspace);
            },
            'workspaceAccess' => function () use ($request): ?array {
                $workspace = $request->route('workspace');
                $role = $workspace instanceof Workspace && $request->user()
                    ? $workspace->roleOf($request->user())
                    : null;

                return $role === null ? null : [
                    'role' => $role->value,
                    'permissions' => array_map(fn ($permission) => $permission->value, $role->permissions()),
                ];
            },
            'viewingAs' => fn () => Impersonation::active($request) && $request->user()
                ? ['name' => $request->user()->name, 'email' => $request->user()->email, 'admin' => Impersonation::impersonator($request)?->name]
                : null,
            'setupGuide' => function () use ($request): ?array {
                $workspace = $request->route('workspace');

                return $request->routeIs('workspace.*') && $workspace instanceof Workspace && $request->user() !== null
                    ? SetupGuide::for($workspace, $request->user())
                    : null;
            },
            'locale' => app()->getLocale(),
            'locales' => config('app.locales'),
            'githubApp' => GitHubApp::enabled(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'staff' => fn () => [
                'admin' => $request->user()?->can('viewAdmin') === true || StaffTwoFactor::missing($request->user()),
                'sales' => $request->user()?->can('viewSales') === true && Router::has('admin.sales.index'),
            ],
            ...($request->routeIs('admin.*') ? ['adminNav' => fn () => app(Extensions::class)->adminNavFor($request->user())] : []),
        ];

        return [...$shared, ...array_diff_key(app(Extensions::class)->sharedPropsFor($request), $shared)];
    }

    protected function siteOrigin(Request $request): string
    {
        $site = rtrim((string) config('app.url'), '/');
        $domain = (string) config('app.domain');
        $host = $request->getHost();

        if ($domain === '' || ($host !== $domain && ! str_ends_with($host, '.'.$domain))) {
            return $site;
        }

        return (parse_url($site, PHP_URL_SCHEME) ?: 'https').'://'.$request->getHttpHost();
    }

    private static function workspacePage(Request $request): ?Workspace
    {
        $route = $request->route();

        if (! $route instanceof Route || ! str_starts_with((string) $route->getDomain(), '{workspace}.')) {
            return null;
        }

        $workspace = $route->parameter('workspace');

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
