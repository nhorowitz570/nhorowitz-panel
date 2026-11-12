<?php

namespace App\Http\Controllers;

use App\Support\Admin\Impersonation;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationController extends Controller
{
    #[Audited]
    public function __invoke(Request $request): Response
    {
        $viewed = $request->user();
        $admin = Impersonation::stop($request);

        if ($admin === null) {
            AuditContext::nothingChanged();
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Inertia::location(rtrim((string) config('app.url'), '/').'/login');
        }

        return Inertia::location(rtrim((string) config('app.url'), '/')."/admin/users/{$viewed->id}");
    }
}
