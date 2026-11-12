<?php

namespace App\Models;

use App\Support\Activity\ActivityLine;
use App\Support\Audit\AuditContext;
use App\Support\Workflows\WorkflowEvents;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property int|null $impersonation_session_id
 * @property string|null $actor_name
 * @property string|null $actor_email
 * @property int|null $machine_id
 * @property string $action
 * @property string|null $subject
 * @property array<string, mixed>|null $meta
 * @property string|null $source
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 * @property-read Machine|null $machine
 * @property-read ImpersonationSession|null $impersonation
 */
class Activity extends Model
{
    public const KEEP_DAYS = 90;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity entries are never edited.'));
        static::deleting(fn () => throw new LogicException('Activity entries are only pruned.'));
    }

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @param  array<string, mixed>  $meta */
    public static function record(Workspace|int $workspace, string $action, ?string $subject = null, ?Machine $machine = null, ?User $user = null, array $meta = [], ?int $viewAs = null): void
    {
        $user ??= auth()->user() instanceof User ? auth()->user() : null;
        $session = $viewAs ?? AuditContext::viewAsSession($user);

        $entry = self::write($workspace, $action, $subject, $machine, $session === null ? $user : null, $meta, $session);
        if ($entry !== null && $session !== null) {
            self::copyForStaff($entry);
        }
    }

    /** @param  array<string, mixed>  $meta */
    public static function system(Workspace|int $workspace, string $action, ?string $subject = null, ?Machine $machine = null, array $meta = []): void
    {
        self::write($workspace, $action, $subject, $machine, null, $meta);

        if (isset(self::WORKFLOW_EVENTS[$action])) {
            $data = array_filter([
                'machine_id' => $machine?->id,
                'machine' => $machine?->name,
                'service_key' => $meta['key'] ?? null,
                'service' => str_starts_with($action, 'service.') || $action === 'backup.failed' ? $subject : null,
                'monitor_id' => $meta['monitor_id'] ?? null,
                'monitor' => str_starts_with($action, 'monitor.') ? $subject : null,
                'cause' => $meta['cause'] ?? $meta['error'] ?? null,
            ], fn ($v) => $v !== null);
            app(WorkflowEvents::class)->dispatch(
                $workspace instanceof Workspace ? $workspace->id : $workspace,
                self::WORKFLOW_EVENTS[$action],
                trim(($subject ?? '').' '.self::WORKFLOW_EVENT_WORDS[$action].(isset($data['cause']) ? ": {$data['cause']}" : '')),
                $data,
            );
        }
    }

    private const WORKFLOW_EVENTS = [
        'monitor.down' => 'monitor.down',
        'monitor.up' => 'monitor.up',
        'service.down' => 'service.down',
        'service.crash_loop' => 'service.crash_loop',
        'machine.offline' => 'machine.offline',
        'machine.disk_full' => 'machine.disk_full',
        'backup.failed' => 'backup.failed',
    ];

    private const WORKFLOW_EVENT_WORDS = [
        'monitor.down' => 'went down',
        'monitor.up' => 'is back up',
        'service.down' => 'stopped',
        'service.crash_loop' => 'keeps restarting',
        'machine.offline' => 'stopped reporting',
        'machine.disk_full' => 'is running out of disk',
        'backup.failed' => 'couldn’t be backed up',
    ];

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function write(Workspace|int $workspace, string $action, ?string $subject, ?Machine $machine, ?User $user, array $meta, ?int $session = null): ?self
    {
        $meta = [...AuditContext::via(), ...$meta];
        $own = $session !== null ? AuditContext::sessionId() === $session : ($user !== null && AuditContext::isRequestUser($user));
        $origin = $own && ($meta['via'] ?? null) !== 'workflow';

        try {
            $entry = self::query()->create([
                'workspace_id' => $workspace instanceof Workspace ? $workspace->id : $workspace,
                'user_id' => $user?->id,
                'impersonation_session_id' => $session,
                'actor_name' => $user?->name,
                'actor_email' => $user?->email,
                'machine_id' => $machine?->id,
                'action' => $action,
                'subject' => $subject === null ? null : mb_substr($subject, 0, 200),
                'meta' => $meta === [] ? null : $meta,
                'source' => AuditContext::source($meta),
                'ip' => $origin ? AuditContext::ip() : null,
                'user_agent' => $origin ? AuditContext::userAgent() : null,
                'request_id' => $origin ? AuditContext::requestId() : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record activity', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
        AuditContext::markRecorded();

        return $entry;
    }

    private static function copyForStaff(self $entry): void
    {
        try {
            $impersonation = $entry->impersonation()->with(['admin', 'user'])->first();
            $workspace = Workspace::query()->find($entry->workspace_id);
            if ($impersonation === null || $workspace === null) {
                return;
            }

            $line = AuditContext::inEnglish(fn () => ActivityLine::describe($entry, true));
            AdminAction::recordForSession($impersonation, 'viewas.write', $workspace, array_filter([
                'activity' => $entry->id,
                'action' => $entry->action,
                'as' => $impersonation->user_name,
                'what' => trim(implode(' ', array_filter([$line['verb'], $line['subject'], $line['after']]))),
                'machine' => $entry->machine?->name,
                'detail' => $line['detail'],
            ], fn ($v) => $v !== null && $v !== ''));
        } catch (Throwable $e) {
            Log::warning('Could not copy View as activity to the admin log', ['activity' => $entry->id, 'error' => $e->getMessage()]);
        }
    }

    public function byPerson(): bool
    {
        return $this->user_id !== null || $this->actor_name !== null || $this->impersonation_session_id !== null;
    }

    /** @param  Builder<self>  $query */
    public function scopeByPeople(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('user_id')->orWhereNotNull('actor_name')->orWhereNotNull('impersonation_session_id'));
    }

    /** @param  Builder<self>  $query */
    public function scopeBySystem(Builder $query): void
    {
        $query->whereNull('user_id')->whereNull('actor_name')->whereNull('impersonation_session_id');
    }

    /** @return BelongsTo<ImpersonationSession, $this> */
    public function impersonation(): BelongsTo
    {
        return $this->belongsTo(ImpersonationSession::class, 'impersonation_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
