<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $machine_id
 * @property int|null $user_id
 * @property int|null $impersonation_session_id
 * @property int|null $machine_policy_id
 * @property int|null $machine_group_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property-read User|null $user
 */
class DaemonEnrolment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
