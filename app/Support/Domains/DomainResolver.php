<?php

namespace App\Support\Domains;

class DomainResolver
{
    /**
     * @return list<string>
     */
    public function addresses(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }

    public function cname(string $domain): ?string
    {
        $records = @dns_get_record($domain, DNS_CNAME) ?: [];
        $target = $records[0]['target'] ?? null;

        return is_string($target) && $target !== '' ? strtolower(rtrim($target, '.')) : null;
    }
}
