<?php

namespace App\Events;

use App\Models\User;
use App\Models\Workspace;

final readonly class WorkspaceCreated
{
    public function __construct(
        public Workspace $workspace,
        public User $owner,
    ) {}
}
