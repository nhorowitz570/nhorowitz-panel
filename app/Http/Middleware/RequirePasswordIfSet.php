<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordIfSet extends RequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null): Response
    {
        /** @var Request $request */
        if ($request->user() !== null && $request->user()->password_set_at === null) {
            return $next($request);
        }

        if ($this->shouldConfirmPassword($request, $passwordTimeoutSeconds === null ? null : (int) $passwordTimeoutSeconds) && ! $request->expectsJson()) {
            $request->session()->reflash();
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
