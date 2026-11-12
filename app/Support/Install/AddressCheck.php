<?php

namespace App\Support\Install;

use App\Http\Middleware\TrustCloudflare;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

class AddressCheck
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::lookUp(...);
    }

    /**
     * @return array{status: 'ok'|'warn'|'fail', lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    public function check(string $host, Request $request): array
    {
        $host = strtolower($host);

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return self::result([['ok', "{$host} and its subdomains always point at the computer you’re on, so this works for trying it out."]]);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::result([['warn', 'That’s an IP address. It works for the panel, but workspaces open on subdomains, so they need a domain.']]);
        }

        if (config('serveros.tunnel') === 'cloudflare') {
            return self::result($this->throughTunnel($host, $request));
        }

        $found = ($this->resolver)($host);
        if ($found === []) {
            return self::result([['fail', "{$host} doesn’t point anywhere yet. Add an A record for it with this server’s address (it can take a few minutes to show up)."]]);
        }

        $ours = $this->serverAddresses($request);
        $lines = [];
        if ($request->getHost() === $host || array_intersect($found, $ours) !== []) {
            $lines[] = ['ok', "{$host} points at this server."];
        } else {
            $lines[] = ['warn', "{$host} points at ".implode(', ', $found).'. Make sure that’s this server.'];
        }

        $wildcard = ($this->resolver)('serveros-check-'.Str::lower(Str::random(6)).'.'.$host);
        $lines[] = $wildcard === []
            ? ['warn', "*.{$host} doesn’t point anywhere yet. Workspaces open on subdomains (acme.{$host}), so add an A record for *.{$host} too."]
            : (array_intersect($wildcard, $found) !== [] || array_intersect($wildcard, $ours) !== []
                ? ['ok', "*.{$host} does too, so workspaces will open."]
                : ['warn', "*.{$host} points at ".implode(', ', $wildcard).', not where '.$host.' does.']);

        return self::result($lines);
    }

    /**
     * Behind a Cloudflare Tunnel: the panel's names are on Cloudflare, and
     * machines reach the gateway on a name of its own that isn't, since
     * they connect with their own certificates, which Cloudflare's proxy
     * can't pass on.
     *
     * @return list<array{0: 'ok'|'warn'|'fail', 1: string}>
     */
    private function throughTunnel(string $host, Request $request): array
    {
        $found = ($this->resolver)($host);
        $lines = [match (true) {
            $found === [] => ['fail', "{$host} doesn’t point anywhere yet. Add it to your tunnel in Cloudflare (Routes, a published application with the service URL http://web:80)."],
            $request->getHost() === $host || self::onCloudflare($found) => ['ok', "{$host} comes through your Cloudflare Tunnel."],
            default => ['warn', "{$host} points at ".implode(', ', $found).', not Cloudflare. Add it to your tunnel’s routes instead.'],
        }];

        $wildcard = ($this->resolver)('serveros-check-'.Str::lower(Str::random(6)).'.'.$host);
        $lines[] = $wildcard === []
            ? ['warn', "*.{$host} doesn’t point anywhere yet. Workspaces open on subdomains (acme.{$host}), so add *.{$host} to the tunnel too."]
            : ['ok', "*.{$host} does too, so workspaces will open."];
        if (substr_count($host, '.') >= 2) {
            $lines[] = ['warn', "Cloudflare’s free certificate covers a domain and one level under it. Workspaces on acme.{$host} are two levels down, so they need Cloudflare’s Advanced Certificate Manager, unless {$host} is the domain itself."];
        }

        $gateway = strtolower((string) parse_url('tls://'.config('daemon.gateway_host'), PHP_URL_HOST));
        if ($gateway === '' || $gateway === $host) {
            $lines[] = ['fail', 'Machines can’t connect through the tunnel. Give the gateway a name of its own that points straight at this server, like gateway.'.$host.' (SERVEROS_GATEWAY_HOST in .env).'];

            return $lines;
        }
        $direct = ($this->resolver)($gateway);
        $lines[] = match (true) {
            $direct === [] => ['warn', "{$gateway}, where machines connect, doesn’t point anywhere yet. Add an A record for it with this server’s address, DNS only."],
            self::onCloudflare($direct) => ['fail', "{$gateway} is behind Cloudflare’s proxy, which machines can’t connect through. Set its record to DNS only (grey cloud), pointing at this server."],
            default => ['ok', "Machines connect to {$gateway} ({$direct[0]}). Keep port 8443 open to them."],
        };

        return $lines;
    }

    /** @param  list<string>  $addresses */
    private static function onCloudflare(array $addresses): bool
    {
        return $addresses !== [] && IpUtils::checkIp($addresses[0], TrustCloudflare::RANGES);
    }

    /** @return list<string> */
    private function serverAddresses(Request $request): array
    {
        $server = $request->server->get('SERVER_ADDR');
        $addresses = [is_string($server) ? $server : '', $request->getHost()];

        return array_values(array_filter($addresses, fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false));
    }

    /** @return list<string> */
    private static function lookUp(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }

    /**
     * @param  list<array{0: 'ok'|'warn'|'fail', 1: string}>  $lines
     * @return array{status: 'ok'|'warn'|'fail', lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    private static function result(array $lines): array
    {
        $statuses = array_column($lines, 0);
        $status = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');

        return ['status' => $status, 'lines' => array_map(fn (array $line) => ['status' => $line[0], 'text' => $line[1]], $lines)];
    }
}
