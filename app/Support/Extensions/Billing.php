<?php

namespace App\Support\Extensions;

use App\Enums\Currency;
use App\Models\Workspace;

interface Billing
{
    public function isBilled(Workspace $workspace): bool;

    public function currency(Workspace $workspace): ?Currency;

    /** @return array{subscriptionStatus: string|null, hasBillingPortal: bool, contract: array{key: string, label: string, machines: int, included: int, extra: int, price: int, extraPrice: int, interval: string}|null} */
    public function summary(Workspace $workspace): array;
}
