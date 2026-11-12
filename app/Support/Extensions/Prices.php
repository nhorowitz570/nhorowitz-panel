<?php

namespace App\Support\Extensions;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\Plan;

interface Prices
{
    public function plan(Plan $plan, BillingInterval $interval, Currency $currency): ?int;

    public function mcpPack(Currency $currency): ?int;
}
