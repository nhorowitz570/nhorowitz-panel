<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $which = $route === null ? '' : ($route->getName() ?? implode('|', $route->methods()).' '.$route->uri());

        return parent::resolveRequestSignature($request).'|'.sha1($which);
    }
}
