<?php

namespace App\Support\Entitlements;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Workspace;

class SelfHostedEntitlements implements Entitlements
{
    public const array FREE_FEATURES = [Feature::StaffRoles, Feature::StatusPages, Feature::Automations];

    public function active(Workspace $workspace): bool
    {
        return true;
    }

    public function has(Workspace $workspace, Feature $feature): bool
    {
        return in_array($feature, self::FREE_FEATURES, true);
    }

    public function limit(Workspace $workspace, Limit $limit): ?int
    {
        $value = config('serveros.self_hosted.'.$limit->value);

        return $value === null ? null : (int) $value;
    }
}
