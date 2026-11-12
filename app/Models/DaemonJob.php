<?php

namespace App\Models;

use App\Models\Concerns\CreditsWhoStartedIt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $machine_id
 * @property int|null $user_id
 * @property int|null $impersonation_session_id
 * @property string $kind
 * @property array<string, mixed>|null $command
 * @property string|null $service
 * @property string $state
 * @property string|null $phase
 * @property int|null $progress
 * @property array<int, string>|null $log
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $error
 * @property CarbonImmutable|null $finished_at
 * @property int|null $workflow_run_id
 * @property string|null $mcp_call_id
 * @property array{kind: string, job: array<string, mixed>, service: string|null}|null $then
 * @property string|null $workflow_node
 * @property int|null $agent_fix_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 * @property-read User|null $user
 */
class DaemonJob extends Model
{
    use CreditsWhoStartedIt;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    // Holds deploy secrets: never send it to the browser.
    protected $hidden = ['command'];

    public const STALE_AFTER_MINUTES = 10;

    protected function casts(): array
    {
        return [
            'command' => 'encrypted:array',
            'log' => 'array',
            'result' => 'array',
            'error' => 'array',
            'then' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->state, ['queued', 'running'], true);
    }

    public function isStale(): bool
    {
        return $this->isActive()
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function isRetryable(): bool
    {
        return $this->kind === 'deploy' && $this->command !== null;
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
