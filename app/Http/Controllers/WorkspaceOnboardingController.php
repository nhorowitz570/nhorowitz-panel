<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceOnboardingController extends Controller
{
    #[NotAudited('Personal: hides the setup guide')]
    public function dismiss(Request $request, Workspace $workspace): RedirectResponse
    {
        $workspace->members()->updateExistingPivot($request->user()->id, ['onboarding_dismissed_at' => now()]);

        return back();
    }

    #[NotAudited('Personal: shows the setup guide again')]
    public function resume(Request $request, Workspace $workspace): RedirectResponse
    {
        $workspace->members()->updateExistingPivot($request->user()->id, ['onboarding_dismissed_at' => null]);

        return back();
    }
}
