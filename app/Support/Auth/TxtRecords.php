<?php

namespace App\Support\Auth;

class TxtRecords
{
    /** @return list<string>|null */
    public function for(string $host): ?array
    {
        $records = @dns_get_record(rtrim($host, '.').'.', DNS_TXT);
        if ($records === false) {
            return null;
        }

        $out = [];
        foreach ($records as $record) {
            $entries = $record['entries'] ?? null;
            $out[] = is_array($entries) ? implode('', $entries) : (string) ($record['txt'] ?? '');
        }

        return $out;
    }
}
