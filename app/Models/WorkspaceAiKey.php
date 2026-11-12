<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $provider
 * @property string $key
 * @property string $hint
 * @property string|null $model
 * @property int|null $user_id
 * @property Carbon|null $verified_at
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class WorkspaceAiKey extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['key'];

    protected function casts(): array
    {
        return ['key' => 'encrypted', 'verified_at' => 'datetime'];
    }

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
