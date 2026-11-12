<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceDomain;
use App\Support\Domains\CustomDomains;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $target = CustomDomains::resolve($host);
        if ($target === null) {
            return $next($request);
        }

        $request->attributes->set('custom_domain', $host);
        $request->attributes->set('custom_workspace', $target['workspace']);
        $request->attributes->set('custom_uri', (string) $request->server->get('REQUEST_URI', '/'));
        config(['session.domain' => null]);

        $path = $request->getPathInfo();
        $statusPath = '/status/'.$target['page'];
        $onStatus = $target['purpose'] === WorkspaceDomain::STATUS
            || ($target['path'] !== null && ($path === '/'.$target['path'] || str_starts_with($path, '/'.$target['path'].'/')));

        if ($target['page'] !== null && $onStatus && $path !== $statusPath && ! str_starts_with($path, $statusPath.'/')) {
            $rest = $target['purpose'] === WorkspaceDomain::STATUS ? $path : substr($path, strlen('/'.$target['path']));
            $request->attributes->set('status_base', $target['purpose'] === WorkspaceDomain::STATUS ? '' : '/'.$target['path']);
            $request->attributes->set('status_page', $target['page']);
            self::rewrite($request, (string) config('app.domain'), $statusPath.(in_array($rest, ['', '/'], true) ? '' : $rest));
        } elseif ($target['purpose'] === WorkspaceDomain::STATUS || str_starts_with($path, '/status/')) {
            self::rewrite($request, (string) config('app.domain'));
        } else {
            self::rewrite($request, $target['slug'].'.'.config('app.domain'));
        }

        return $next($request);
    }

    public static function rewrite(Request $request, string $host, ?string $path = null, ?string $uri = null): void
    {
        $server = $request->server->all();
        $server['HTTP_HOST'] = $host;
        $server['SERVER_NAME'] = $host;
        if ($uri !== null) {
            $server['REQUEST_URI'] = $uri;
        } elseif ($path !== null) {
            $query = $request->server->get('QUERY_STRING');
            $server['REQUEST_URI'] = $path.(is_string($query) && $query !== '' ? '?'.$query : '');
        }

        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server,
            $request->getContent(),
        );
    }
}
