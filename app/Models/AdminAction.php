<?php

namespace App\Models;

use App\Support\Audit\AuditContext;
use App\Support\Extensions\Extensions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * @property int $id
 * @property int|null $admin_id
 * @property string|null $admin_name
 * @property string|null $admin_email
 * @property int|null $impersonation_session_id
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $target_label
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 * @property-read User|null $admin
 * @property-read ImpersonationSession|null $impersonation
 */
class AdminAction extends Model
{
    public const UPDATED_AT = null;

    public const array TARGETS = [
        'user' => User::class,
        'workspace' => Workspace::class,
    ];

    public const int KEEP_DAYS = 730;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Admin actions are never edited.'));
        static::deleting(fn () => throw new LogicException('Admin actions are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** @return BelongsTo<ImpersonationSession, $this> */
    public function impersonation(): BelongsTo
    {
        return $this->belongsTo(ImpersonationSession::class, 'impersonation_session_id');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(User $admin, string $action, ?Model $target = null, array $meta = [], ?ImpersonationSession $session = null): void
    {
        $current = $session->id ?? AuditContext::sessionId();
        self::write($admin->id, $admin->name, $admin->email, $current, $action, $target, $meta, here: true);

        if ($target instanceof User && $current === null) {
            AccountEvent::fromStaff($admin, $action, $target, $meta);
        }
    }

    /** @param  array<string, mixed>  $meta */
    public static function recordForSession(ImpersonationSession $session, string $action, ?Model $target = null, array $meta = []): void
    {
        $admin = $session->admin;
        self::write($admin?->id, $admin->name ?? $session->admin_name, $admin->email ?? $session->admin_email, $session->id, $action, $target, $meta, here: AuditContext::sessionId() === $session->id);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function write(?int $adminId, string $adminName, string $adminEmail, ?int $session, string $action, ?Model $target, array $meta, bool $here): void
    {
        self::query()->create([
            'admin_id' => $adminId,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'impersonation_session_id' => $session,
            'action' => $action,
            'target_type' => $target === null ? null : self::typeOf($target),
            'target_id' => $target?->getKey(),
            'target_label' => $target === null ? null : mb_substr(self::labelOf($target), 0, 160),
            'meta' => $meta ?: null,
            'ip' => $here ? AuditContext::ip() : null,
            'user_agent' => $here ? AuditContext::userAgent() : null,
        ]);
        AuditContext::markRecorded();
    }

    public static function recordSave(User $admin, Model $target, ?bool $wasLive = null, ?bool $isLive = null): void
    {
        $changed = self::changed($target);
        if ($changed === []) {
            AuditContext::nothingChanged();

            return;
        }
        $verb = match (true) {
            $wasLive === null || $wasLive === $isLive => 'updated',
            $isLive => 'published',
            default => 'unpublished',
        };

        self::record($admin, self::typeOf($target).'.'.$verb, $target, [
            'changed' => array_values(array_diff($changed, ['published', 'published_at'])),
        ]);
    }

    /** @return list<string> */
    private static function changed(Model $model): array
    {
        return array_values(array_diff(array_keys($model->getChanges()), ['updated_at', 'created_at', 'updated_by_id']));
    }

    /** @return array<string, class-string<Model>> */
    public static function targets(): array
    {
        return [...self::TARGETS, ...array_map(fn (array $target) => $target['class'], app(Extensions::class)->auditTargets())];
    }

    public static function typeOf(Model $target): string
    {
        $type = array_search($target::class, self::targets(), true);
        if ($type === false) {
            throw new InvalidArgumentException('Add '.$target::class.' to AdminAction::TARGETS (or a package\'s, with Extensions::addAuditTarget) first.');
        }

        return $type;
    }

    private static function labelOf(Model $target): string
    {
        foreach (app(Extensions::class)->auditTargets() as $package) {
            if ($target::class === $package['class']) {
                return $package['label'] === null ? "#{$target->getKey()}" : (string) ($package['label'])($target);
            }
        }

        return (string) match (true) {
            $target instanceof User, $target instanceof Workspace => $target->name,
            default => "#{$target->getKey()}",
        };
    }

    /** @param  Builder<AdminAction>  $query */
    public function scopeAbout(Builder $query, Model $target): void
    {
        $query->where('target_type', self::typeOf($target))->where('target_id', $target->getKey());
    }

    public function adminName(): string
    {
        return $this->admin->name ?? $this->admin_name ?? 'A former admin';
    }

    /** @return list<array<string, mixed>> */
    public static function historyFor(User|Workspace $target): array
    {
        return array_values(self::query()
            ->about($target)
            ->with('admin:id,name,email')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AdminAction $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'admin' => $a->adminName(),
                'meta' => $a->meta ?? [],
                'createdAt' => $a->created_at->toIso8601String(),
            ])
            ->all());
    }
}
