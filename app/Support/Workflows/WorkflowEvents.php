<?php

namespace App\Support\Workflows;

use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Workflow;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkflowEvents
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /** @param  array<string, mixed>  $data */
    public function dispatch(int $workspaceId, string $event, string $summary, array $data = []): int
    {
        $started = 0;

        if (isset($data['machine_id']) && Machine::query()->whereKey($data['machine_id'])->first()?->inMaintenance()) {
            return 0;
        }
        if (isset($data['monitor_id']) && Monitor::query()->whereKey($data['monitor_id'])->first()?->muted()) {
            return 0;
        }

        try {
            $workflows = Workflow::query()
                ->with('workspace')
                ->where('workspace_id', $workspaceId)
                ->where('enabled', true)
                ->where('trigger', $event)
                ->get();

            foreach ($workflows as $workflow) {
                if (! $this->matches($workflow->triggerRules(), $data)) {
                    continue;
                }
                if ($workflow->last_run_at?->gt(now()->subSeconds(Workflow::COOLDOWN_SECONDS))) {
                    Log::info('Workflow run skipped: cooldown', ['workflow' => $workflow->id, 'event' => $event]);

                    continue;
                }
                if ($this->engine->start($workflow, $event, $summary, $data) !== null) {
                    $started++;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $started;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $data
     */
    private function matches(array $rules, array $data): bool
    {
        $filled = fn (string $key) => isset($rules[$key]) && $rules[$key] !== '';

        if ($filled('machine_id') && (int) $rules['machine_id'] !== (int) ($data['machine_id'] ?? 0)) {
            return false;
        }
        if ($filled('monitor_id') && (int) $rules['monitor_id'] !== (int) ($data['monitor_id'] ?? 0)) {
            return false;
        }
        if ($filled('service') && ! in_array($rules['service'], [$data['service'] ?? null, $data['service_key'] ?? null], true)) {
            return false;
        }
        if ($filled('outcome') && $rules['outcome'] !== 'any' && $rules['outcome'] !== ($data['outcome'] ?? null)) {
            return false;
        }

        return true;
    }
}
