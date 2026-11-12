<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property int $position
 * @property int|null $source_id
 * @property string|null $refresh
 * @property int|null $pull_request
 * @property string|null $head
 * @property CarbonImmutable|null $refreshed_at
 * @property CarbonImmutable|null $removing_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read ProjectEnvironment|null $source
 */
#[Fillable(['name', 'position', 'source_id', 'refresh'])]
class ProjectEnvironment extends Model
{
    public const array REFRESH = ['daily', 'weekly'];

    protected function casts(): array
    {
        return ['refreshed_at' => 'immutable_datetime', 'removing_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectEnvironment, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }

    /** @return HasMany<ProjectNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(ProjectNode::class, 'environment_id');
    }

    public function refreshDue(CarbonImmutable $now): bool
    {
        if ($this->refresh === null || $this->source_id === null) {
            return false;
        }
        $every = $this->refresh === 'weekly' ? 7 * 24 : 24;

        return $this->refreshed_at === null || $this->refreshed_at->diffInHours($now) >= $every - 1;
    }
}
