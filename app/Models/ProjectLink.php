<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $app_node_id
 * @property int $resource_node_id
 * @property list<string>|null $env
 * @property string $state
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read ProjectNode $app
 * @property-read ProjectNode $resource
 */
#[Fillable(['app_node_id', 'resource_node_id'])]
class ProjectLink extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['env' => 'array'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectNode, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(ProjectNode::class, 'app_node_id');
    }

    /** @return BelongsTo<ProjectNode, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(ProjectNode::class, 'resource_node_id');
    }
}
