<?php

namespace App\Support\Machines;

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Auth\GoogleTeamAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class MovableMachines
{
    /** @return Collection<int, Machine> */
    public static function for(User $user, Request $request): Collection
    {
        $workspaces = $user->workspaces()->get()
            ->filter(fn (Workspace $workspace) => self::canMoveOut($workspace, $user, $request));

        return Machine::query()
            ->whereIn('workspace_id', $workspaces->modelKeys())
            ->with('workspace:id,name,slug')
            ->orderBy('workspace_id')->orderBy('name')
            ->get();
    }

    public static function canMoveOut(Workspace $workspace, User $user, Request $request): bool
    {
        $session = $request->hasSession() ? $request->session() : null;
        $via = $session?->get(GoogleTeamAccess::VIA);
        $hd = $session?->get(GoogleTeamAccess::HD);

        return Gate::forUser($user)->allows('delete', $workspace)
            && $workspace->satisfiesTwoFactor($user, $request)
            && $workspace->satisfiesGoogleSignIn($user, is_string($via) ? $via : null, is_string($hd) ? $hd : null)
            && (! $workspace->require_sso || $workspace->owner_id === $user->id);
    }
}
