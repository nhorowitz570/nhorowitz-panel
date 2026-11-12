<?php

namespace App\Models;

use App\Enums\WorkspacePermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property list<string> $permissions
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'description', 'permissions'])]
class WorkspaceCustomRole extends Model
{
    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function grants(WorkspacePermission $permission): bool
    {
        return in_array($permission->value, $this->permissions, true);
    }

    /**
     * @return array{id: int, name: string, description: string|null, permissions: list<string>}
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions,
        ];
    }
}
