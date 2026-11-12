<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Ssr\HttpGateway;
use Inertia\Ssr\Response;
use Throwable;

class CachedSsrGateway extends HttpGateway
{
    private const TTL_SECONDS = 3600;

    /**
     * @param  array<string, mixed>  $page
     */
    public function dispatch(array $page, ?Request $request = null): ?Response
    {
        $request ??= request();
        if (! $this->ssrIsEnabled($request) || $request->user() !== null || app()->isLocal()) {
            return parent::dispatch($page, $request);
        }

        $key = 'ssr:'.sha1((string) json_encode($page));
        try {
            $kept = Cache::get($key);
        } catch (Throwable) {
            $kept = null;
        }
        if (is_string($kept) && ($decoded = @gzinflate($kept)) !== false) {
            $parts = json_decode($decoded, true);
            if (is_array($parts) && is_string($parts['head'] ?? null) && is_string($parts['body'] ?? null)) {
                return new Response($parts['head'], $parts['body']);
            }
        }

        $response = parent::dispatch($page, $request);
        if ($response !== null && $response->body !== '') {
            try {
                Cache::put($key, gzdeflate((string) json_encode(['head' => $response->head, 'body' => $response->body]), 6), self::TTL_SECONDS);
            } catch (Throwable) {
            }
        }

        return $response;
    }
}
