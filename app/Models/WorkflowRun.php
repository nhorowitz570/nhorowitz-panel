<?php

namespace App\Models;

use App\Casts\WorkflowGraph;
use App\Models\Concerns\CreditsWhoStartedIt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workflow_id
 * @property int|null $user_id
 * @property int|null $impersonation_session_id
 * @property string $state
 * @property array{kind: string, summary: string, data?: array<string, mixed>} $trigger
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property array<string, array<string, mixed>> $steps
 * @property list<string> $frontier
 * @property list<array{t: int, node: string|null, level: string, message: string}> $logs
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $wake_at
 * @property-read Workflow $workflow
 * @property-read User|null $user
 */
class WorkflowRun extends Model
{
    use CreditsWhoStartedIt;

    public const KEEP_DAYS = 30;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trigger' => 'array',
            'graph' => WorkflowGraph::class,
            'steps' => 'array',
            'frontier' => 'array',
            'logs' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'wake_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function finished(): bool
    {
        return in_array($this->state, ['succeeded', 'failed', 'cancelled'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPageProps(bool $commandOutput = true): array
    {
        $shown = $this->shown($commandOutput);

        return [
            'id' => $this->id,
            'state' => $this->state,
            'trigger' => $this->trigger,
            'user' => $this->startedByName(),
            'steps' => (object) $shown['steps'],
            'logs' => $shown['logs'],
            'startedAt' => $this->started_at->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'seconds' => (int) $this->started_at->diffInSeconds($this->finished_at ?? now()),
        ];
    }

    /**
     * @return array{steps: array<string, array<string, mixed>>, logs: list<array{t: int, node: string|null, level: string, message: string}>}
     */
    public function shown(bool $commandOutput): array
    {
        if ($commandOutput) {
            return ['steps' => $this->steps ?? [], 'logs' => $this->logs ?? []];
        }

        $kinds = collect($this->graph['nodes'] ?? [])->mapWithKeys(fn (array $node) => [(string) ($node['id'] ?? '') => $node['kind'] ?? null]);
        $runsCommands = fn (?string $node) => $node !== null && in_array($kinds[$node] ?? null, ['command', 'agent'], true);

        $steps = [];
        foreach ($this->steps ?? [] as $id => $step) {
            if ($runsCommands((string) $id)) {
                unset($step['summary']);
                if (is_string($step['message'] ?? null)) {
                    $step['message'] = self::withoutStdout($step['message']);
                }
            }
            $steps[$id] = $step;
        }

        $logs = [];
        foreach ($this->logs ?? [] as $line) {
            if (! $runsCommands($line['node'])) {
                $logs[] = $line;
            } elseif ($line['level'] !== 'info') {
                $logs[] = [...$line, 'message' => self::withoutStdout($line['message'])];
            }
        }

        return ['steps' => $steps, 'logs' => $logs];
    }

    private static function withoutStdout(string $message): string
    {
        $at = mb_strpos($message, 'Command finished: ');

        return $at === false ? $message : mb_substr($message, 0, $at).'Command finished';
    }
}
