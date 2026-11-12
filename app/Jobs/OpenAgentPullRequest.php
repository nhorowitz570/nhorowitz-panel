<?php

namespace App\Jobs;

use App\Support\Workflows\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class OpenAgentPullRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $runId, public string $node, public string $jobId) {}

    public function handle(WorkflowEngine $engine): void
    {
        $engine->agentPullRequest($this->runId, $this->node, $this->jobId);
    }
}
