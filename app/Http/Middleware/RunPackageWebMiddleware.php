<?php

namespace App\Http\Middleware;

use App\Support\Extensions\Extensions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

class RunPackageWebMiddleware
{
    public function __construct(private readonly Extensions $extensions) {}

    public function handle(Request $request, Closure $next, string $slot = Extensions::WEB): Response
    {
        $middleware = $this->extensions->middleware($slot);
        if ($middleware === []) {
            return $next($request);
        }

        return (new Pipeline(app()))->send($request)->through($middleware)->then($next);
    }
}
