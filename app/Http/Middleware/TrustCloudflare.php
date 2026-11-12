<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class TrustCloudflare
{
    public const array RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public const string COUNTRY = 'cloudflare.country';

    /** Where cloudflared reaches the panel from: the server itself or its Docker network. */
    public const array TUNNEL_RANGES = ['127.0.0.0/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    public function handle(Request $request, Closure $next): Response
    {
        $edge = (string) $request->server->get('REMOTE_ADDR');
        $visitor = trim((string) $request->headers->get('CF-Connecting-IP'));

        $tunnel = self::throughTunnel($edge);
        if ($visitor !== '' && filter_var($visitor, FILTER_VALIDATE_IP) && ($tunnel || IpUtils::checkIp($edge, self::RANGES))) {
            $request->server->set('REMOTE_ADDR', $visitor);
            if ($tunnel && strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https') {
                $request->server->set('HTTPS', 'on');
            }
            $request->headers->remove('X-Forwarded-For');

            $country = strtoupper(trim((string) $request->headers->get('CF-IPCountry')));
            if (preg_match('/^[A-Z][A-Z0-9]$/', $country) === 1 && $country !== 'XX') {
                $request->attributes->set(self::COUNTRY, $country);
            }
        }

        return $next($request);
    }

    /**
     * Self-hosted behind a Cloudflare Tunnel, cloudflared hands requests on
     * from a private address with Cloudflare's headers. The panel's ports
     * are only open to the server then, so those headers can be believed.
     */
    private static function throughTunnel(string $from): bool
    {
        return config('serveros.tunnel') === 'cloudflare' && $from !== '' && IpUtils::checkIp($from, self::TUNNEL_RANGES);
    }
}
