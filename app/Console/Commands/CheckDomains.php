<?php

namespace App\Console\Commands;

use App\Models\WorkspaceDomain;
use App\Support\Domains\Cloudflare;
use App\Support\Domains\CustomDomains;
use Illuminate\Console\Command;

class CheckDomains extends Command
{
    protected $signature = 'domains:check';

    protected $description = 'Check workspace domains waiting on DNS and certificates, and that active ones still point here';

    public function handle(CustomDomains $domains): int
    {
        $direct = CustomDomains::direct();
        if (! Cloudflare::enabled() && ! $direct) {
            return self::SUCCESS;
        }

        $pending = WorkspaceDomain::query()->where('state', 'pending')
            ->when($direct, fn ($query) => $query->whereNull('cloudflare_id')->whereNotNull('hostname'), fn ($query) => $query->whereNotNull('cloudflare_id'))
            ->get();
        foreach ($pending as $domain) {
            $age = $domain->created_at?->diffInMinutes(now()) ?? 0;
            $every = $age < 60 ? 1 : 10;
            if ($domain->checked_at !== null && $domain->checked_at->gt(now()->subMinutes($every)->addSeconds(5))) {
                continue;
            }
            if ($age > 14 * 24 * 60) {
                $domain->forceFill(['state' => 'failed', 'error' => $direct ? 'Nothing pointed at this panel for two weeks. Check the DNS records and try again.' : 'Nothing pointed at ServerOS for two weeks. Check the DNS records and try again.'])->save();
                CustomDomains::forget();

                continue;
            }
            $domains->refresh($domain);
        }

        $active = WorkspaceDomain::query()->where('state', 'active')->whereNotNull('hostname')
            ->when($direct, fn ($query) => $query->whereNull('cloudflare_id'), fn ($query) => $query->whereNotNull('cloudflare_id'))
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinutes(10)))
            ->get();
        foreach ($active as $domain) {
            $domains->refresh($domain);
        }

        return self::SUCCESS;
    }
}
