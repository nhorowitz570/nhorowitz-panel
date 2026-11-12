<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $name
 * @property list<array{name: string, version: string|null}> $packages
 * @property string $mode
 * @property bool $allow_downgrade
 * @property int|null $batch_size
 * @property bool $stop_on_failure
 * @property string $reboot
 * @property CarbonImmutable|null $scheduled_for
 * @property string $state
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 */
class PackageRollout extends Model
{
    public const array MODES = ['latest', 'exact'];

    public const array REBOOTS = ['never', 'if_required'];

    public const array OPEN = ['pending', 'running', 'paused'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'packages' => 'array',
            'allow_downgrade' => 'boolean',
            'stop_on_failure' => 'boolean',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
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

    /** @return HasMany<PackageRolloutMachine, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PackageRolloutMachine::class)->orderBy('position');
    }

    public function everything(): bool
    {
        return $this->packages === [];
    }
}
