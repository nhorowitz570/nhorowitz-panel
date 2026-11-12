<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestoreCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->attributes->get('custom_domain');
        if (is_string($host)) {
            $uri = $request->attributes->get('custom_uri');
            ResolveCustomDomain::rewrite($request, $host, uri: is_string($uri) ? $uri : null);
            app('url')->setRequest($request);
        }

        return $next($request);
    }
}
