<?php

namespace App\Jobs;

use App\Models\AgentFix;
use App\Support\Fixes\FixDiagnoser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class DiagnoseFix implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $fixId)
    {
        $this->onQueue((string) config('services.groq.agent_queue', 'default'));
    }

    public function handle(FixDiagnoser $diagnoser): void
    {
        $fix = AgentFix::query()->with('machine')->find($this->fixId);
        if ($fix === null || $fix->state !== 'investigating') {
            return;
        }

        try {
            if ($fix->machine->workspace_id !== $fix->workspace_id) {
                throw new RuntimeException('The machine moved to another workspace.');
            }
            $diagnoser->diagnose($fix);
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                report($e);
            }
            $fix->forceFill([
                'state' => 'error',
                'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong while the agent was looking.',
                'finished_at' => now(),
            ])->save();
        }
    }
}
