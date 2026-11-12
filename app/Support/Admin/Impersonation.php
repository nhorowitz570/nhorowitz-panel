<?php

namespace App\Support\Admin;

use App\Models\AdminAction;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Support\Audit\AuditContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Impersonation
{
    public const string KEY = 'impersonator_id';

    private const string SESSION = 'impersonation_session_id';

    private const string RESOLVED = 'impersonation.admin';

    public static function start(Request $request, User $admin, User $user, string $reason): void
    {
        $session = ImpersonationSession::query()->create([
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'reason' => $reason,
            'ip' => AuditContext::ip(),
            'user_agent' => AuditContext::userAgent(),
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);
        AdminAction::record($admin, 'user.impersonated', $user, ['reason' => $reason], session: $session);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget('oauth.connect');
        $request->session()->put(self::KEY, $admin->id);
        $request->session()->put(self::SESSION, $session->id);
        $request->attributes->remove(self::RESOLVED);
    }

    public static function stop(Request $request): ?User
    {
        $admin = self::impersonator($request);
        self::end($request, $admin === null ? 'admin_revoked' : 'returned');
        $request->session()->forget([self::KEY, self::SESSION]);
        $request->attributes->remove(self::RESOLVED);
        if ($admin === null) {
            return null;
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return $admin;
    }

    public static function end(Request $request, string $how): void
    {
        $id = self::sessionId($request);
        if ($id !== null) {
            ImpersonationSession::query()->find($id)?->finish($how);
        }
    }

    public static function active(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::KEY);
    }

    public static function impersonator(Request $request): ?User
    {
        if (! self::active($request)) {
            return null;
        }

        if (! $request->attributes->has(self::RESOLVED)) {
            $admin = User::query()->whereKey($request->session()->get(self::KEY))->first();
            $request->attributes->set(self::RESOLVED, $admin?->is_admin ? $admin : null);
        }

        return $request->attributes->get(self::RESOLVED);
    }

    public static function sessionId(Request $request): ?int
    {
        if (! self::active($request)) {
            return null;
        }
        $id = $request->session()->get(self::SESSION);

        return is_numeric($id) ? (int) $id : null;
    }
}
