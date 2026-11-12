<?php

namespace App\Support\Extensions;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Models\User;
use App\Models\Workspace;
use App\Support\InstallPing;
use App\Support\Mcp\OAuthAudience;
use App\Support\Mcp\WorkspaceAudience;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Extensions
{
    public const string WEB = 'web';

    public const string WORKSPACE = 'workspace';

    public const string API = 'api';

    public const string ADMIN = 'admin';

    public const string SIGN_IN = 'sign-in';

    public const string MCP = 'mcp';

    public const string WORKSPACE_SIGN_IN = 'workspace-sign-in';

    public const string PASSWORD_SIGN_IN = 'password-sign-in';

    public const string SUBDOMAINS = 'subdomains';

    public const string WEB_END = 'web-end';

    /** @var array<string, list<Closure(): void>> */
    private array $routes = [];

    /** @var array<string, NavItem> */
    private array $navItems = [];

    /** @var array<string, SettingsItem> */
    private array $settingsItems = [];

    private ?UpgradeLink $upgrade = null;

    private ?MarketingSite $marketingSite = null;

    /** @var array<string, string> */
    private array $offLimitsWhileViewingAs = [];

    /** @var array<string, list<class-string>> */
    private array $middleware = [];

    /** @var list<SignInHooks> */
    private array $signInHooks = [];

    /** @var list<SignUpHooks> */
    private array $signUpHooks = [];

    /** @var array<string, list<Closure(Request, Workspace|null): array<string, mixed>>> */
    private array $pageProps = [];

    /** @var list<Closure(Workspace, list<int>): array<int, array<string, mixed>>> */
    private array $memberProps = [];

    /** @var list<Closure(Throwable, Request): (Response|null)> */
    private array $exceptionRenderers = [];

    /** @var array<string, OAuthAudience> */
    private array $oauthAudiences = [];

    private ?WorkspaceAudience $workspaceAudience = null;

    /** @var list<string> */
    private array $mainDomainRoutes = [];

    /** @var list<string> */
    private array $accountRoutes = [];

    /** @var array<string, AdminNavItem> */
    private array $adminNav = [];

    /** @var array<string, array{class: class-string<Model>, label: Closure|null}> */
    private array $auditTargets = [];

    /** @var list<Closure(Request): array<string, mixed>> */
    private array $sharedProps = [];

    /** @var array<string, Closure(): mixed> */
    private array $installStats = [];

    /** @var (Closure(Workspace): string)|null */
    private ?Closure $poweredBy = null;

    /** @var array<string, string> */
    private array $accountEvents = [];

    /** @var array<class-string<Model>, array<string, string>> */
    private array $modelCasts = [];

    /** @var list<Closure(Tool, McpContext): (string|null)> */
    private array $toolGuards = [];

    /** @var list<Closure(array<string, mixed>): (string|null)> */
    private array $headTags = [];

    /** @param  'pages'|'marketing/pages'  $folder */
    public static function pageEntry(string $component, string $folder, string $extension): string
    {
        $own = "resources/js/{$folder}/{$component}.{$extension}";

        if (is_file(base_path($own))) {
            return $own;
        }

        $base = base_path().DIRECTORY_SEPARATOR;
        foreach (glob(base_path("vendor/serveroshq/*/resources/js/{$folder}/{$component}.{$extension}")) ?: [] as $path) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base)));
        }

        return $own;
    }

    /** @param  Closure(): void  $register */
    public function routes(string $slot, Closure $register): void
    {
        if (! in_array($slot, [self::WEB, self::WORKSPACE, self::API, self::ADMIN, self::SIGN_IN, self::MCP, self::SUBDOMAINS], true)) {
            throw new InvalidArgumentException("There's no route slot called {$slot}.");
        }

        $this->routes[$slot][] = $register;
    }

    public function loadRoutes(string $slot): void
    {
        foreach ($this->routes[$slot] ?? [] as $register) {
            $register();
        }
    }

    /** @param  class-string  $middleware */
    public function addMiddleware(string $slot, string $middleware): void
    {
        if (! in_array($slot, [self::WEB, self::WEB_END, self::WORKSPACE, self::WORKSPACE_SIGN_IN, self::PASSWORD_SIGN_IN], true)) {
            throw new InvalidArgumentException("There's no middleware slot called {$slot}.");
        }

        $this->middleware[$slot][] = $middleware;
    }

    /** @return list<class-string> */
    public function middleware(string $slot): array
    {
        return $this->middleware[$slot] ?? [];
    }

    public function addOAuthAudience(OAuthAudience $audience): void
    {
        if ($audience->key() === $this->workspaceAudience()->key()) {
            throw new InvalidArgumentException("{$audience->key()} is the panel's own OAuth audience.");
        }

        $this->oauthAudiences[$audience->key()] = $audience;
    }

    /** @return array<string, OAuthAudience> */
    public function oauthAudiences(): array
    {
        return [$this->workspaceAudience()->key() => $this->workspaceAudience(), ...$this->oauthAudiences];
    }

    public function oauthAudience(string $key): ?OAuthAudience
    {
        return $this->oauthAudiences()[$key] ?? null;
    }

    private function workspaceAudience(): WorkspaceAudience
    {
        return $this->workspaceAudience ??= new WorkspaceAudience;
    }

    /** @param  list<string>  $routeNamePrefixes */
    public function keepOnMainDomain(array $routeNamePrefixes): void
    {
        $this->mainDomainRoutes = array_values(array_unique([...$this->mainDomainRoutes, ...$routeNamePrefixes]));
    }

    /** @return list<string> */
    public function mainDomainRoutes(): array
    {
        return $this->mainDomainRoutes;
    }

    /** @param  list<string>  $routeNames */
    public function addAccountRoutes(array $routeNames): void
    {
        $this->accountRoutes = array_values(array_unique([...$this->accountRoutes, ...$routeNames]));
    }

    /** @return list<string> */
    public function accountRoutes(): array
    {
        return $this->accountRoutes;
    }

    public function addAdminNavItem(AdminNavItem $item): void
    {
        $this->adminNav[$item->key] = $item;
    }

    /** @return list<array{key: string, items: list<array<string, mixed>>}> */
    public function adminNavFor(?User $user): array
    {
        $groups = [];
        foreach (AdminNavItem::GROUPS as $group) {
            $items = array_filter($this->adminNav, fn (AdminNavItem $item) => $item->group === $group && $item->visibleTo($user));
            if ($items !== []) {
                $groups[] = ['key' => $group, 'items' => array_map(fn (AdminNavItem $item) => $item->toArray(), AdminNavItem::arrange(array_values($items)))];
            }
        }

        return $groups;
    }

    // The short name is stored as target_type: never change it once rows exist.
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  (Closure(TModel): string)|null  $label
     */
    public function addAuditTarget(string $type, string $class, ?Closure $label = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $type) !== 1) {
            throw new InvalidArgumentException("An audit target's name is snake_case, like blog_post: {$type} isn't.");
        }

        $this->auditTargets[$type] = ['class' => $class, 'label' => $label];
    }

    /** @return array<string, array{class: class-string<Model>, label: Closure|null}> */
    public function auditTargets(): array
    {
        return $this->auditTargets;
    }

    /** @param  Closure(Request): array<string, mixed>  $props */
    public function addSharedProps(Closure $props): void
    {
        $this->sharedProps[] = $props;
    }

    /** @return array<string, mixed> */
    public function sharedPropsFor(Request $request): array
    {
        $props = [];
        foreach ($this->sharedProps as $add) {
            $props = [...$props, ...$add($request)];
        }

        return $props;
    }

    public function addSignInHooks(SignInHooks $hooks): void
    {
        $this->signInHooks[] = $hooks;
    }

    /** @return list<SignInHooks> */
    public function signInHooks(): array
    {
        return $this->signInHooks;
    }

    public function addSignUpHooks(SignUpHooks $hooks): void
    {
        $this->signUpHooks[] = $hooks;
    }

    /** @return list<SignUpHooks> */
    public function signUpHooks(): array
    {
        return $this->signUpHooks;
    }

    public function signedUp(Request $request, User $user): void
    {
        foreach ($this->signUpHooks as $hooks) {
            $hooks->signedUp($request, $user);
        }
    }

    public function signUpSource(User $user): ?string
    {
        foreach ($this->signUpHooks as $hooks) {
            if (($source = $hooks->source($user)) !== null) {
                return $source;
            }
        }

        return null;
    }

    public function addNavItem(NavItem $item): void
    {
        $this->navItems[$item->key] = $item;
    }

    public function addSettingsItem(SettingsItem $item): void
    {
        $this->settingsItems[$item->key] = $item;
    }

    public function hasSettingsItem(string $key): bool
    {
        return isset($this->settingsItems[$key]);
    }

    /** @return list<array<string, mixed>> */
    public function navFor(Workspace $workspace, ?User $user): array
    {
        return array_values(array_map(
            fn (NavItem $item) => $item->toArray(),
            array_filter($this->navItems, fn (NavItem $item) => $item->visibleTo($workspace, $user)),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function settingsFor(Workspace $workspace, ?User $user): array
    {
        return array_values(array_map(
            fn (SettingsItem $item) => $item->toArray(),
            array_filter($this->settingsItems, fn (SettingsItem $item) => $item->visibleTo($workspace, $user)),
        ));
    }

    public function setUpgradeLink(?UpgradeLink $link): void
    {
        $this->upgrade = $link;
    }

    public function upgradeLink(): ?UpgradeLink
    {
        return $this->upgrade;
    }

    public function setMarketingSite(MarketingSite $site): void
    {
        $this->marketingSite = $site;
    }

    public function marketingSite(): ?MarketingSite
    {
        return $this->marketingSite;
    }

    /** @return array{href: string, label: string}|null */
    public function upgradeFor(Workspace $workspace): ?array
    {
        return $this->upgrade?->toArray($workspace);
    }

    public function upgradeUrl(Workspace $workspace): ?string
    {
        return $this->upgrade?->url($workspace);
    }

    /** @param  list<string>  $routes */
    public function offLimitsWhileViewingAs(array $routes, string $message): void
    {
        foreach ($routes as $route) {
            $this->offLimitsWhileViewingAs[$route] = $message;
        }
    }

    public function offLimitsMessage(?string $route): ?string
    {
        return $route === null ? null : ($this->offLimitsWhileViewingAs[$route] ?? null);
    }

    /**
     * @param  Closure(Request, Workspace|null): array<string, mixed>  $props
     */
    public function addPageProps(string $component, Closure $props): void
    {
        $this->pageProps[$component][] = $props;
    }

    /** @return array<string, mixed> */
    public function pagePropsFor(string $component, Request $request, ?Workspace $workspace = null): array
    {
        $props = [];
        foreach ($this->pageProps[$component] ?? [] as $add) {
            $props = [...$props, ...$add($request, $workspace)];
        }

        return $props;
    }

    /** @param  Closure(Workspace, list<int>): array<int, array<string, mixed>>  $props */
    public function addMemberProps(Closure $props): void
    {
        $this->memberProps[] = $props;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    public function memberPropsFor(Workspace $workspace, array $userIds): array
    {
        $props = [];
        foreach ($this->memberProps as $add) {
            foreach ($add($workspace, $userIds) as $id => $fields) {
                $props[$id] = [...($props[$id] ?? []), ...$fields];
            }
        }

        return $props;
    }

    /** @param  Closure(Throwable, Request): (Response|null)  $render */
    public function addExceptionRenderer(Closure $render): void
    {
        $this->exceptionRenderers[] = $render;
    }

    public function renderException(Throwable $e, Request $request): ?Response
    {
        foreach ($this->exceptionRenderers as $render) {
            if (($response = $render($e, $request)) !== null) {
                return $response;
            }
        }

        return null;
    }

    /** @param  Closure(): (int|bool)  $value */
    public function addInstallStat(string $name, Closure $value): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) {
            throw new InvalidArgumentException("An install stat's name is snake_case, like licensed: {$name} isn't.");
        }
        if (in_array($name, InstallPing::FIELDS, true) && ! in_array($name, InstallPing::PACKAGE_FIELDS, true)) {
            throw new InvalidArgumentException("{$name} is one of the install ping's own fields.");
        }

        $this->installStats[$name] = $value;
    }

    /** @return array<string, int|bool> */
    public function installStats(): array
    {
        $stats = [];
        foreach ($this->installStats as $name => $value) {
            try {
                $answer = $value();
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if (is_bool($answer) || (is_int($answer) && $answer >= 0)) {
                $stats[$name] = $answer;
            }
        }

        return $stats;
    }

    /** @param  Closure(Workspace): string  $url */
    public function setPoweredByLink(Closure $url): void
    {
        $this->poweredBy = $url;
    }

    public function poweredByUrl(Workspace $workspace): string
    {
        return $this->poweredBy !== null
            ? ($this->poweredBy)($workspace)
            : rtrim((string) config('app.url'), '/').'/';
    }

    public function addAccountEvent(string $event, string $text): void
    {
        $this->accountEvents[$event] = $text;
    }

    public function accountEventText(string $event): ?string
    {
        return $this->accountEvents[$event] ?? null;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $casts
     */
    public function addModelCasts(string $model, array $casts): void
    {
        $this->modelCasts[$model] = [...$this->modelCasts[$model] ?? [], ...$casts];
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    public function modelCasts(string $model): array
    {
        return $this->modelCasts[$model] ?? [];
    }

    /** @param  Closure(Tool, McpContext): (string|null)  $guard */
    public function addToolGuard(Closure $guard): void
    {
        $this->toolGuards[] = $guard;
    }

    public function toolRefusal(Tool $tool, McpContext $context): ?string
    {
        foreach ($this->toolGuards as $guard) {
            if (($refusal = $guard($tool, $context)) !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /** @param  Closure(array<string, mixed>): (string|null)  $tag */
    public function addHeadTag(Closure $tag): void
    {
        $this->headTags[] = $tag;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<HtmlString>
     */
    public function headTags(array $page): array
    {
        $tags = [];
        foreach ($this->headTags as $tag) {
            if (($html = $tag($page)) !== null && $html !== '') {
                $tags[] = new HtmlString($html);
            }
        }

        return $tags;
    }
}
