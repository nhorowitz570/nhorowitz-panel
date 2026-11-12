<?php

use App\Http\Middleware\AccountGuard;
use App\Http\Middleware\AdminOnMainDomain;
use App\Http\Middleware\AllowWorkspacePasskeys;
use App\Http\Middleware\BindCustomDomainSession;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePasswordIfSet;
use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\RestoreCustomDomain;
use App\Http\Middleware\RunPackageWebMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SendToInstaller;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottlePerRoute;
use App\Http\Middleware\TrackViewAs;
use App\Http\Middleware\TrustCloudflare;
use App\Support\Edition;
use App\Support\ErrorPage;
use App\Support\Extensions\Extensions;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->group(base_path('routes/mcp.php'));
            if (Edition::selfHosted()) {
                require base_path('routes/installer.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ResolveCustomDomain::class);
        $middleware->prepend(SendToInstaller::class);
        // First, so everything after it (the installer's redirect, too) sees
        // the visitor's address and HTTPS behind Cloudflare or its tunnel.
        $middleware->prepend(TrustCloudflare::class);

        $middleware->trimStrings(except: ['db_password', 'mail_password']);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'cookie_consent', 'locale', 'ask_chat']);

        $middleware->alias(['password.confirm' => RequirePasswordIfSet::class]);

        $middleware->alias(['throttle' => ThrottlePerRoute::class]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->attributes->has('custom_domain')
            ? url('/login')
            : rtrim((string) config('app.url'), '/').'/login');

        $middleware->web(prepend: [SecurityHeaders::class, RestoreCustomDomain::class, AdminOnMainDomain::class]);

        $middleware->web(append: [
            BindCustomDomainSession::class,
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AccountGuard::class,
            RunPackageWebMiddleware::class,
            TrackViewAs::class,
            AllowWorkspacePasskeys::class,
            RunPackageWebMiddleware::class.':'.Extensions::WEB_END,
        ]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, BindCustomDomainSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'scim/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (Throwable $e, Request $request) => app(Extensions::class)->renderException($e, $request));

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/v1', 'api/v1/*')) {
                return null;
            }
            [$type, $message] = match ($e->getStatusCode()) {
                404 => ['not_found', 'There’s no '.$request->method().' /'.$request->path().' route. GET /api/v1/openapi.json lists them all.'],
                405 => ['method_not_allowed', $request->method().' isn’t allowed on /'.$request->path().'. Allowed: '.($e->getHeaders()['Allow'] ?? '').'.'],
                default => ['error', $e->getMessage() ?: 'That didn’t work.'],
            };

            return response()->json(['error' => ['type' => $type, 'message' => $message]], $e->getStatusCode(), $e->getHeaders());
        });

        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => ErrorPage::respond($response, $request));
    })->create();
