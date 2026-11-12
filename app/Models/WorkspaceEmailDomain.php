<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $domain
 * @property string $token
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['domain'])]
class WorkspaceEmailDomain extends Model
{
    public const string RECORD_PREFIX = '_serveros-verify';

    public const string VALUE_PREFIX = 'serveros-domain-verification=';

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkspaceEmailDomain $domain): void {
            $domain->token = $domain->token ?: Str::lower(Str::random(32));
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function recordName(): string
    {
        return self::RECORD_PREFIX.'.'.$this->domain;
    }

    public function recordValue(): string
    {
        return self::VALUE_PREFIX.$this->token;
    }

    public function verifiedElsewhere(): bool
    {
        return self::query()
            ->where('domain', $this->domain)
            ->whereNotNull('verified_at')
            ->where('workspace_id', '!=', $this->workspace_id)
            ->exists();
    }

    /** @return array{id: int, domain: string, verified: bool, verifiedAt: string|null, checkedAt: string|null, record: array{name: string, value: string}} */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'verified' => $this->isVerified(),
            'verifiedAt' => $this->verified_at?->toIso8601String(),
            'checkedAt' => $this->checked_at?->toIso8601String(),
            'record' => ['name' => $this->recordName(), 'value' => $this->recordValue()],
        ];
    }
}
