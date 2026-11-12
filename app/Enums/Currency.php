<?php

namespace App\Enums;

use App\Http\Middleware\TrustCloudflare;
use App\Models\Workspace;
use App\Support\Extensions\Billing;
use Illuminate\Http\Request;

enum Currency: string
{
    case GBP = 'GBP';
    case USD = 'USD';
    case EUR = 'EUR';

    private const array EURO_COUNTRIES = [
        'AT', 'BE', 'HR', 'CY', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PT', 'SK', 'SI', 'ES',
        'AD', 'MC', 'SM', 'VA', 'ME', 'XK',
    ];

    private const array DOLLAR_COUNTRIES = [
        'US', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM', 'EC', 'SV', 'PA', 'TL', 'FM', 'MH', 'PW',
    ];

    public function symbol(): string
    {
        return match ($this) {
            self::GBP => '£',
            self::USD => '$',
            self::EUR => '€',
        };
    }

    public static function forCountry(?string $country): self
    {
        $country = strtoupper((string) $country);

        return match (true) {
            in_array($country, self::DOLLAR_COUNTRIES, true) => self::USD,
            in_array($country, self::EURO_COUNTRIES, true) => self::EUR,
            default => self::GBP,
        };
    }

    public static function forVisitor(Request $request): self
    {
        $picked = self::tryFrom(strtoupper((string) $request->query('currency')));

        return $picked ?? self::forCountry($request->attributes->get(TrustCloudflare::COUNTRY));
    }

    public static function forWorkspace(Workspace $workspace, Request $request): self
    {
        return app(Billing::class)->currency($workspace) ?? self::forVisitor($request);
    }
}
