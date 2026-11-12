<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $package_rollout_id
 * @property int $machine_id
 * @property int $position
 * @property string|null $job_id
 * @property string|null $reboot_job_id
 * @property string $state
 * @property string|null $outcome
 * @property list<array{name: string, version: string|null}>|null $installed
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PackageRollout $rollout
 * @property-read Machine $machine
 */
class PackageRolloutMachine extends Model
{
    public const array FINISHED = ['done', 'failed', 'skipped'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'installed' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PackageRollout, $this> */
    public function rollout(): BelongsTo
    {
        return $this->belongsTo(PackageRollout::class, 'package_rollout_id');
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
