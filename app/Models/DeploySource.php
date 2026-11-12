<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $token
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $filename
 * @property int $size
 * @property int $received
 * @property string $status
 * @property string|null $commit
 * @property int|null $files
 * @property array<string, mixed>|null $detection
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['token', 'filename', 'size', 'received', 'status', 'commit', 'files', 'detection', 'expires_at'])]
class DeploySource extends Model
{
    public const MAX_BYTES = 1024 * 1024 * 1024;

    public const CHUNK_BYTES = 5 * 1024 * 1024;

    public const KEEP_HOURS = 24;

    public const DAILY_BYTES = 5 * self::MAX_BYTES;

    public const STALLED_MINUTES = 60;

    protected function casts(): array
    {
        return ['detection' => 'array', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function directory(): string
    {
        return storage_path('app/private/deploy-sources/'.$this->token);
    }

    public function zipPath(): string
    {
        return $this->directory().'/upload.zip';
    }

    public function repoPath(): string
    {
        return $this->directory().'/repo.git';
    }

    public static function fromRepoUrl(?string $url): ?self
    {
        if (! preg_match('#/api/deploy-sources/([A-Za-z0-9]{48})\.git$#', (string) $url, $match)) {
            return null;
        }

        return self::query()->where('token', $match[1])->first();
    }

    public function repoUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/deploy-sources/'.$this->token.'.git';
    }

    public function usable(): bool
    {
        return $this->status === 'ready' && $this->expires_at->isFuture();
    }
}
