<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $purpose
 * @property string|null $hostname
 * @property string|null $path
 * @property string|null $status_page_slug
 * @property string|null $cloudflare_id
 * @property string $state
 * @property string|null $ssl_state
 * @property array<string, mixed>|null $verification
 * @property string|null $error
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
class WorkspaceDomain extends Model
{
    public const DASHBOARD = 'dashboard';

    public const STATUS = 'status';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'verification' => 'array',
            'verified_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function active(): bool
    {
        return $this->state === 'active';
    }

    public function onPath(): bool
    {
        return $this->hostname === null && $this->path !== null;
    }
}
