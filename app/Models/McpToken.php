<?php

namespace App\Models;

use App\Mcp\Scopes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $audience
 * @property int|null $workspace_id
 * @property int $user_id
 * @property int|null $mcp_oauth_client_id
 * @property string $kind
 * @property string $name
 * @property list<string> $scopes
 * @property string $token_hash
 * @property string $token_prefix
 * @property string|null $refresh_hash
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $refresh_expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_used_ip
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 * @property-read McpOAuthClient|null $client
 */
class McpToken extends Model
{
    public const KEY_PREFIX = 'sos_';

    public const ACCESS_MINUTES = 60;

    public const REFRESH_DAYS = 30;

    public const WORKSPACE = 'workspace';

    public const API = 'api';

    protected $guarded = ['id'];

    protected $attributes = ['audience' => self::WORKSPACE];

    protected $hidden = ['token_hash', 'refresh_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
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

    /** @return BelongsTo<McpOAuthClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(McpOAuthClient::class, 'mcp_oauth_client_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function secret(string $kind): string
    {
        return match ($kind) {
            'key' => self::KEY_PREFIX.Str::random(40),
            'refresh' => 'sor_'.Str::random(48),
            default => 'soa_'.Str::random(48),
        };
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: self, 1: string}
     */
    public static function issueKey(Workspace $workspace, User $user, string $name, array $scopes, string $audience = self::WORKSPACE): array
    {
        $secret = self::secret('key');
        $token = self::query()->create([
            'audience' => $audience,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'kind' => 'key',
            'name' => $name,
            'scopes' => Scopes::normalise($scopes),
            'token_hash' => self::hash($secret),
            'token_prefix' => substr($secret, 0, 12),
        ]);

        return [$token, $secret];
    }

    public static function findActive(string $secret): ?self
    {
        $token = self::query()->active()->where('token_hash', self::hash($secret))->first();
        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }

    public static function revokeFor(Workspace $workspace, User $user): void
    {
        self::query()->active()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->get()
            ->each(fn (self $token) => $token->revoke());
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now(), 'refresh_hash' => null])->save();
        }
    }

    public function label(): string
    {
        return $this->kind === 'oauth' ? ($this->client->name ?? $this->name) : $this->name;
    }
}
