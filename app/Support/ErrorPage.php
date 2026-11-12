<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ErrorPage
{
    private const STATUSES = [403, 404, 419, 429, 500, 503];

    public static function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::STATUSES, true)
            || $request->expectsJson()
            || $request->is('api/*', 'scim/*')
            || ($status >= 500 && config('app.debug'))) {
            return $response;
        }

        $locale = SetLocale::for($request);
        App::setLocale($locale);
        Carbon::setLocale($locale);

        try {
            $site = app(Extensions::class)->marketingSite();
            if ($site !== null && self::onSite($request)) {
                Inertia::setRootView($site->rootView);
            }

            return Inertia::render('errors/Error', [
                'status' => $status,
                'home' => self::home($request),
                'site' => rtrim((string) config('app.url'), '/').'/',
                'support' => (string) config('mail.support.address'),
            ])->toResponse($request)->setStatusCode($status);
        } catch (\Throwable) {
            return $response;
        }
    }

    private static function onSite(Request $request): bool
    {
        $domain = (string) config('app.domain');

        return in_array($request->getHost(), [$domain, "docs.{$domain}", "feedback.{$domain}"], true);
    }

    /** @return array{href: string, label: string} */
    private static function home(Request $request): array
    {
        $site = rtrim((string) config('app.url'), '/').'/';
        $domain = (string) config('app.domain');
        $host = $request->getHost();

        if ($host === "docs.{$domain}") {
            return ['href' => '/', 'label' => 'Back to the docs'];
        }

        if ($host !== $domain && str_ends_with($host, ".{$domain}")) {
            $slug = substr($host, 0, -strlen(".{$domain}"));
            if ($request->user() !== null && Workspace::query()->where('slug', $slug)->exists()) {
                return ['href' => '/', 'label' => 'Back to dashboard'];
            }
        }

        return ['href' => $site, 'label' => 'Go to the homepage'];
    }
}
