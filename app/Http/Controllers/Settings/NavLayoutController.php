<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit\NotAudited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NavLayoutController extends Controller
{
    public const LAYOUTS = ['top', 'sidebar'];

    #[NotAudited('Personal: where your own dashboard shows its navigation')]
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'layout' => ['required', 'string', Rule::in(self::LAYOUTS)],
        ]);

        $request->user()
            ->forceFill(['nav_layout' => $validated['layout'] === 'sidebar' ? null : $validated['layout']])
            ->save();

        return back();
    }
}
