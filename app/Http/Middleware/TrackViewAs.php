<?php

namespace App\Http\Middleware;

use App\Models\AdminAction;
use App\Models\ImpersonationSession;
use App\Models\ImpersonationView;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Admin\Impersonation;
use App\Support\Audit\AuditCoverage;
use App\Support\Audit\PageName;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackViewAs
{
    private const array SECRET_PARAMETERS = ['token', 'signature', 'secret', 'code'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $id = Impersonation::sessionId($request);
        if ($id === null) {
            return $response;
        }

        if (Cache::add("view-as:{$id}:seen", true, 60)) {
            ImpersonationSession::query()->whereKey($id)->whereNull('ended_at')->update(['last_seen_at' => now()]);
        }

        $route = $request->route();
        $name = $route?->getName();
        if ($request->isMethod('GET') && $name !== null && $response->isSuccessful()) {
            $parameters = [];
            foreach ($route->originalParameters() as $key => $value) {
                $parameters[$key] = ! is_scalar($value) ? null : (in_array($key, self::SECRET_PARAMETERS, true) ? '…' : (string) $value);
            }
            if (Cache::add("view-as:{$id}:page:".md5($name.json_encode($parameters)), true, 60)) {
                ImpersonationView::query()->create([
                    'impersonation_session_id' => $id,
                    'route' => mb_substr($name, 0, 120),
                    'parameters' => $parameters === [] ? null : $parameters,
                ]);
            }
        }

        if ($route instanceof Route && AuditCoverage::wroteSilently($request, $response)
            && ($admin = Impersonation::impersonator($request)) !== null) {
            $this->noteChange($request, $route, $admin);
        }

        return $response;
    }

    private function noteChange(Request $request, Route $route, User $admin): void
    {
        $name = $route->getName() ?? $route->uri();
        $parts = explode('.', $name);
        $verb = match (end($parts)) {
            'store' => 'added',
            'destroy' => 'removed',
            default => 'changed',
        };
        if (in_array(end($parts), ['store', 'update', 'destroy'], true)) {
            array_pop($parts);
        }

        $workspace = $route->parameter('workspace');
        $machine = $route->parameter('machine');
        $viewed = $request->user();

        AdminAction::record($admin, 'viewas.write', $workspace instanceof Workspace ? $workspace : ($viewed instanceof User ? $viewed : null), array_filter([
            'action' => Str::limit($name, 120, ''),
            'as' => $viewed instanceof User ? $viewed->name : null,
            'what' => $verb.' '.PageName::of(implode('.', $parts)),
            'machine' => $machine instanceof Machine ? $machine->name : null,
            'staff_only' => true,
        ]));
    }
}
