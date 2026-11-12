<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $uid
 * @property string $name
 * @property array{enabled?: bool, repo?: string, machine_id?: int, copy_data?: bool, domain?: string|null, limit?: int, user_id?: int}|null $previews
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['name'])]
class Project extends Model
{
    public const int MAX_NETWORKS = 16384;

    protected function casts(): array
    {
        return ['previews' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->uid ??= strtolower(Str::random(10));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<ProjectNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(ProjectNode::class);
    }

    /** @return HasMany<ProjectEnvironment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(ProjectEnvironment::class)->whereNull('removing_at')->orderBy('position')->orderBy('id');
    }

    public function production(): ProjectEnvironment
    {
        return $this->environments()->first()
            ?? $this->environments()->create(['name' => 'Production', 'position' => 0]);
    }

    /** @return HasMany<ProjectLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class);
    }

    /** @return HasMany<ProjectMachine, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMachine::class);
    }

    public function prefix(): string
    {
        $index = $this->id % self::MAX_NETWORKS;

        return '10.'.(64 + intdiv($index, 256)).'.'.($index % 256);
    }

    public function cidr(): string
    {
        return $this->prefix().'.0/24';
    }

    public function interface(): string
    {
        return 'sos'.$this->uid;
    }
}
