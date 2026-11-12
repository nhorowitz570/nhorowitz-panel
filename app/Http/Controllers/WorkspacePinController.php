<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspacePinController extends Controller
{
    #[NotAudited('Personal: your pinned items')]
    public function store(Request $request, Workspace $workspace, Workspace $pinned): RedirectResponse
    {
        $this->pin($request, $pinned, now());

        return back();
    }

    #[NotAudited('Personal: your pinned items')]
    public function destroy(Request $request, Workspace $workspace, Workspace $pinned): RedirectResponse
    {
        $this->pin($request, $pinned, null);

        return back();
    }

    private function pin(Request $request, Workspace $pinned, mixed $at): void
    {
        abort_unless($request->user()->workspaces()->whereKey($pinned->id)->exists(), 404);

        $request->user()->workspaces()->updateExistingPivot($pinned->id, ['pinned_at' => $at]);
    }
}
