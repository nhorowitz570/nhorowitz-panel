<?php

namespace App\Support\Extensions;

use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

interface TrialCards
{
    public function assign(User $owner, Workspace $workspace): void;

    public function needsCard(Workspace $workspace): bool;

    public function awaitingFirstCharge(Workspace $workspace): bool;

    public function trialDays(Workspace $workspace): int;

    public function verifyPath(Workspace $workspace): ?string;

    /**
     * @throws ConnectionException|RuntimeException
     */
    public function checkout(Workspace $workspace, Plan $plan, BillingInterval $interval, User $by, string $returnUrl): string;
}
