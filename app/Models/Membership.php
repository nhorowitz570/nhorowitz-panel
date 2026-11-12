<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $role
 * @property int|null $custom_role_id
 * @property string|null $onboarding_dismissed_at
 * @property string|null $pinned_at
 * @property string|null $alert_emails
 * @property int|null $weekly_summary
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Membership extends Pivot
{
    protected $table = 'user_workspace';

    public $incrementing = true;
}
