<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class DaemonInstallScriptController extends Controller
{
    public function __invoke(): Response
    {
        $script = (string) file_get_contents((string) (config('daemon.install_script') ?: resource_path('daemon/install.sh')));

        return response(strtr($script, [
            '__SERVEROS_INSTALL_URL__' => self::safe(config('daemon.install_url')),
            '__SERVEROS_PANEL_URL__' => self::safe(config('daemon.panel_url')),
            '__SERVEROS_RELEASES_URL__' => self::safe(config('daemon.releases_url')),
            '__SERVEROS_RELEASE_PUBKEY__' => self::key(config('daemon.release_pubkey')),
        ]), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function key(mixed $key): string
    {
        return is_string($key) && preg_match('/^[0-9a-f]{64}$/i', trim($key)) === 1 ? strtolower(trim($key)) : '';
    }

    private static function safe(mixed $url): string
    {
        return is_string($url) && preg_match('#^https?://[A-Za-z0-9.\-:\[\]/_~%]+$#', $url) === 1 ? rtrim($url, '/') : '';
    }
}
