<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const string COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::for($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    public static function for(Request $request): string
    {
        $supported = array_map('strval', array_keys(config('app.locales')));
        try {
            $user = $request->user();
        } catch (\Throwable) {
            $user = null;
        }
        $chosen = $user->locale ?? $request->cookie(self::COOKIE);

        if (is_string($chosen) && in_array($chosen, $supported, true)) {
            return $chosen;
        }

        $browser = $request->getPreferredLanguage($supported);

        return is_string($browser) && in_array($browser, $supported, true) ? $browser : (string) config('app.fallback_locale', 'en');
    }
}
