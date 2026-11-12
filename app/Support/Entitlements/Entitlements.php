<?php

namespace App\Support\Entitlements;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Workspace;

interface Entitlements
{
    public function active(Workspace $workspace): bool;

    public function has(Workspace $workspace, Feature $feature): bool;

    public function limit(Workspace $workspace, Limit $limit): ?int;
}
