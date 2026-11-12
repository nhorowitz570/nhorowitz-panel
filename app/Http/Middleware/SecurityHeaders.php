<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $policy = (string) $headers->get('Content-Security-Policy');
        if ($policy === '') {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }
        if (! str_contains($policy, 'frame-ancestors') && ! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if ($request->isSecure() && str_starts_with((string) config('app.url'), 'https://') && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
