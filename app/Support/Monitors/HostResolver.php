<?php

namespace App\Support\Monitors;

use App\Support\Edition;
use Symfony\Component\HttpFoundation\IpUtils;

class HostResolver
{
    private const array BLOCKED = [
        ...IpUtils::PRIVATE_SUBNETS,
        '192.0.0.0/24',
        '192.88.99.0/24',
        '224.0.0.0/4',
        '::ffff:0:0:0/96',
        '100::/64',
        '2001:10::/28',
        '2001:20::/28',
        'ff00::/8',
    ];

    /** @return list<string> */
    public function resolve(string $host): array
    {
        return $this->lookup($host, $this->privateAllowed());
    }

    /** @return list<string> */
    public function resolveOnLan(string $host): array
    {
        return $this->lookup($host, Edition::selfHosted());
    }

    public function allowed(string $ip): bool
    {
        return $this->permits($ip, $this->privateAllowed());
    }

    /** @return list<string> */
    private function lookup(string $host, bool $private): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->permits($host, $private) ? [$host] : [];
        }

        $addresses = $this->addresses($host);
        foreach ($addresses as $ip) {
            if (! $this->permits($ip, $private)) {
                return [];
            }
        }

        usort($addresses, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return $addresses;
    }

    /** @return list<string> */
    protected function addresses(string $host): array
    {
        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return $addresses !== [] ? $addresses : array_values(array_filter((array) @gethostbynamel($host)));
    }

    private function permits(string $ip, bool $private): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($private) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($ip, self::BLOCKED);
    }

    private function privateAllowed(): bool
    {
        return Edition::selfHosted() && (bool) config('monitors.allow_private');
    }
}
