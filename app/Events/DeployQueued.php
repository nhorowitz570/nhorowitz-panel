<?php

namespace App\Events;

use App\Models\DaemonJob;
use App\Models\Machine;

final readonly class DeployQueued
{
    /** @param  array<mixed>  $domains */
    public function __construct(
        public Machine $machine,
        public DaemonJob $job,
        public array $domains,
    ) {}
}
