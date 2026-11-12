<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Cache;

class MailExchangers
{
    /** @return list<string> */
    public function for(string $domain): array
    {
        return Cache::remember('mx:'.$domain, now()->addDay(), function () use ($domain): array {
            $records = @dns_get_record($domain.'.', DNS_MX);

            return is_array($records)
                ? array_values(array_filter(array_map(fn ($r) => rtrim(strtolower((string) ($r['target'] ?? '')), '.'), $records)))
                : [];
        });
    }
}
