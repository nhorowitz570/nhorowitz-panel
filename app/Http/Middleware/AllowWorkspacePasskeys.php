<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowWorkspacePasskeys
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = (string) config('app.domain');
        $host = strtolower($request->getHost());

        if ($domain !== '' && str_ends_with($host, '.'.$domain)) {
            config(['passkeys.allowed_origins' => array_values(array_unique([
                ...(array) config('passkeys.allowed_origins', []),
                $request->getSchemeAndHttpHost(),
            ]))]);
        }

        return $next($request);
    }
}
