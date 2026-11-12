<?php

namespace App\Models;

use App\Support\Audit\AccountEventLine;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Device;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $email
 * @property string $event
 * @property string|null $method
 * @property array<string, mixed>|null $meta
 * @property int|null $staff_id
 * @property string|null $staff_name
 * @property int|null $impersonation_session_id
 * @property string|null $ip
 * @property string|null $country
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 * @property-read ImpersonationSession|null $impersonation
 */
class AccountEvent extends Model
{
    public const KEEP_DAYS = 365;

    public const FAILED_KEEP_DAYS = 90;

    public const array FAILED = ['signin.failed', 'signin.locked_out'];

    public const array FROM_STAFF = [
        'user.created' => 'account.created',
        'user.verified' => 'email.verified',
        'user.two_factor_reset' => 'two_factor.off',
        'user.password_reset_sent' => 'password.reset_requested',
        'user.signed_out' => 'sessions.signed_out',
        'user.suspended' => 'account.suspended',
        'user.unsuspended' => 'account.unsuspended',
    ];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Account events are never edited.'));
        static::deleting(fn () => throw new LogicException('Account events are only pruned.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ImpersonationSession, $this> */
    public function impersonation(): BelongsTo
    {
        return $this->belongsTo(ImpersonationSession::class, 'impersonation_session_id');
    }

    /** @param  array<string, mixed>  $meta */
    public static function record(User $user, string $event, array $meta = [], ?string $method = null): ?self
    {
        $session = AuditContext::viewAsSession($user);
        $impersonation = $session === null ? null : ImpersonationSession::query()->find($session);

        $entry = self::write($user, $event, $meta, $method, $impersonation?->admin_id, $impersonation?->admin_name, $session);
        if ($entry !== null && $impersonation !== null) {
            try {
                AdminAction::recordForSession($impersonation, 'viewas.write', $user, [
                    'action' => 'account.'.$event,
                    'as' => $impersonation->user_name,
                    'what' => lcfirst(AuditContext::inEnglish(fn () => AccountEventLine::text($entry, true))),
                ]);
            } catch (Throwable $e) {
                Log::warning('Could not copy a View as account change to the admin log', ['event' => $event, 'error' => $e->getMessage()]);
            }
        }

        return $entry;
    }

    /** @param  array<string, mixed>  $meta */
    public static function fromStaff(User $admin, string $action, User $user, array $meta = []): void
    {
        $write = fn (string $event, array $meta = []) => self::write($user, $event, $meta, null, $admin->id, $admin->name);

        if ($action === 'user.updated') {
            if (in_array('name', $meta['changed'] ?? [], true)) {
                $write('name.changed', ['name' => $user->name]);
            }
            if (isset($meta['email'])) {
                $write('email.changed', ['email' => $meta['email']]);
            }

            return;
        }

        if ($action === 'user.session_signed_out') {
            $write('sessions.signed_out', ['scope' => 'one', 'device' => $meta['device'] ?? null]);

            return;
        }

        if (isset(self::FROM_STAFF[$action])) {
            $write(self::FROM_STAFF[$action], $action === 'user.signed_out' ? ['scope' => 'everywhere'] : []);
        }
    }

    public static function lockout(?User $user): void
    {
        $key = 'account-lockout:'.($user->id ?? 'none').':'.AuditContext::ip();
        if (cache()->add($key, true, 60)) {
            self::write($user, 'signin.locked_out', [], null);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function write(?User $user, string $event, array $meta, ?string $method, ?int $staffId = null, ?string $staffName = null, ?int $session = null): ?self
    {
        AuditContext::markRecorded();

        try {
            return self::query()->create([
                'user_id' => $user?->id,
                'email' => $user?->email,
                'event' => $event,
                'method' => $method,
                'meta' => $meta === [] ? null : $meta,
                'staff_id' => $staffId,
                'staff_name' => $staffName,
                'impersonation_session_id' => $session,
                'ip' => AuditContext::ip(),
                'country' => AuditContext::country(),
                'user_agent' => AuditContext::userAgent(),
                'request_id' => AuditContext::requestId(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record an account event', ['event' => $event, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function byStaff(): bool
    {
        return $this->staff_id !== null || $this->staff_name !== null || $this->impersonation_session_id !== null;
    }

    public static function prune(): int
    {
        return self::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete()
            + self::query()->whereIn('event', self::FAILED)->where('created_at', '<', now()->subDays(self::FAILED_KEEP_DAYS))->delete();
    }

    /** @return list<array{id: int, text: string, by: string|null, device: string|null, ip: string|null, tone: string, createdAt: string}> */
    public static function forPerson(User $user, int $limit = 30): array
    {
        return array_values(self::query()->where('user_id', $user->id)->latest('id')->limit($limit)->get()
            ->map(fn (self $e) => [
                'id' => $e->id,
                'text' => AccountEventLine::text($e),
                'by' => $e->byStaff() ? AuditContext::supportName() : null,
                'device' => $e->byStaff() ? null : Device::label($e->user_agent),
                'ip' => $e->byStaff() ? null : $e->ip,
                'tone' => AccountEventLine::tone($e),
                'createdAt' => $e->created_at->toIso8601String(),
            ])->all());
    }

    /** @return list<array<string, mixed>> */
    public static function forStaff(User $user, int $limit = 100): array
    {
        return array_values(self::query()->where('user_id', $user->id)->latest('id')->limit($limit)->get()
            ->map(fn (self $e) => self::staffRow($e))->all());
    }

    /** @return array<string, mixed> */
    public static function staffRow(self $e): array
    {
        return [
            'id' => $e->id,
            'event' => $e->event,
            'text' => AccountEventLine::text($e, true),
            'tone' => AccountEventLine::tone($e),
            'user' => $e->user_id === null ? null : ['id' => $e->user_id],
            'email' => $e->email,
            'staff' => $e->staff_name,
            'session' => $e->impersonation_session_id,
            'device' => Device::label($e->user_agent),
            'userAgent' => $e->user_agent,
            'ip' => $e->ip,
            'country' => $e->country,
            'createdAt' => $e->created_at->toIso8601String(),
        ];
    }
}
