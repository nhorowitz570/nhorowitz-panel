<?php

namespace App\Support\Domains;

use App\Models\Machine;
use App\Support\Machines\CloudflareTunnel;
use Symfony\Component\HttpFoundation\IpUtils;

class DomainCheck
{
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function __construct(private readonly DomainResolver $resolver) {}

    /** @return array{domain: string, status: string, expected: string|null, found: list<string>, record: array{type: string, name: string, value: string}|null} */
    public function check(Machine $machine, string $domain): array
    {
        return $this->lookup($machine, strtolower($domain), strtolower($domain));
    }

    /** @return array{domain: string, status: string, expected: string|null, found: list<string>, record: array{type: string, name: string, value: string}|null} */
    public function checkWildcard(Machine $machine, string $domain): array
    {
        $domain = strtolower($domain);
        $probe = 'serveros-check-'.bin2hex(random_bytes(4)).'.'.$domain;

        return [...$this->lookup($machine, $probe, '*.'.$domain), 'domain' => '*.'.$domain];
    }

    /**
     * @return array{domain: string, status: string, expected: string|null, found: list<string>, record: array{type: string, name: string, value: string}|null}
     */
    private function lookup(Machine $machine, string $domain, string $recordName): array
    {
        if (CloudflareTunnel::active($machine)) {
            return $this->throughTunnel($machine, $domain, $recordName);
        }

        $expected = $machine->ip_address ?: null;
        $found = $this->resolver->addresses($domain);

        $status = match (true) {
            $found === [] => 'missing',
            $expected !== null && in_array($expected, $found, true) => 'ready',
            IpUtils::checkIp($found[0], self::CLOUDFLARE) => 'proxied',
            default => 'elsewhere',
        };

        return [
            'domain' => $domain,
            'status' => $status,
            'expected' => $expected,
            'found' => $found,
            'record' => $expected === null ? null : [
                'type' => str_contains($expected, ':') ? 'AAAA' : 'A',
                'name' => $recordName,
                'value' => $expected,
            ],
        ];
    }

    /**
     * Behind a Cloudflare Tunnel the name points at Cloudflare (a proxied
     * CNAME to the tunnel), never at the machine: Cloudflare's addresses are
     * what ready looks like.
     *
     * @return array{domain: string, status: string, expected: string|null, found: list<string>, record: array{type: string, name: string, value: string}|null}
     */
    private function throughTunnel(Machine $machine, string $domain, string $recordName): array
    {
        $target = CloudflareTunnel::target($machine);
        $found = $this->resolver->addresses($domain);

        return [
            'domain' => $domain,
            'status' => match (true) {
                $found === [] => 'missing',
                IpUtils::checkIp($found[0], self::CLOUDFLARE) => 'ready',
                default => 'elsewhere',
            },
            'expected' => $target,
            'found' => $found,
            'record' => $target === null ? null : ['type' => 'CNAME', 'name' => $recordName, 'value' => $target],
        ];
    }
}
