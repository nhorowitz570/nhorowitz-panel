<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $machine_id
 * @property int|null $created_by
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $reason
 * @property bool $public
 * @property string $action
 * @property string $state
 * @property string|null $phase
 * @property string|null $job_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $outcome
 * @property-read Machine $machine
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 */
class MaintenanceWindow extends Model
{
    public const ACTIONS = ['none', 'reboot', 'updates'];

    public const MAX_DAYS_AHEAD = 60;

    public const SHOWN_DAYS_AHEAD = 14;

    protected $guarded = ['id'];

    protected $attributes = ['state' => 'scheduled', 'action' => 'none', 'public' => true];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'public' => 'boolean',
        ];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeUpcoming(Builder $query, int $days = self::SHOWN_DAYS_AHEAD): void
    {
        $query->where('state', 'scheduled')->where('starts_at', '<=', now()->addDays($days))->orderBy('starts_at');
    }

    public function minutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** @return array<string, mixed> */
    public function toCard(bool $private = true): array
    {
        return [
            'id' => $this->id,
            'startsAt' => $this->starts_at->toIso8601String(),
            'endsAt' => $this->ends_at->toIso8601String(),
            'reason' => $this->reason,
            'state' => $this->state,
            ...($private ? [
                'action' => $this->action,
                'public' => $this->public,
                'phase' => $this->phase,
                'outcome' => $this->outcome,
                'by' => $this->creator?->name,
            ] : []),
        ];
    }
}
