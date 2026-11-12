<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOnMainDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $main = (string) config('app.domain');

        if (($request->is('admin') || $request->is('admin/*')) && ! $request->is('admin/return') && $main !== '' && $request->getHost() !== $main) {
            return redirect()->away(rtrim((string) config('app.url'), '/').$request->getRequestUri());
        }

        return $next($request);
    }
}
