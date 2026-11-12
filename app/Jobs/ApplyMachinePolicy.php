<?php

namespace App\Jobs;

use App\Models\Machine;
use App\Support\Machines\MachinePolicies;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ApplyMachinePolicy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param  array<string, mixed>|null  $reported */
    public function __construct(public int $machineId, public ?array $reported = null) {}

    public function handle(): void
    {
        $machine = Machine::query()->find($this->machineId);
        if ($machine !== null) {
            MachinePolicies::sync($machine, $this->reported);
        }
    }
}
