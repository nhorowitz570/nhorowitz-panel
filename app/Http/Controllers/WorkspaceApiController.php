<?php

namespace App\Http\Controllers;

use App\Enums\Limit;
use App\Enums\WorkspacePermission;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\ConsoleLock;
use App\Support\Docs\DocsUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceApiController extends Controller
{
    public const MAX_KEYS = 20;

    public function show(Request $request, Workspace $workspace, ConsoleLock $console): Response
    {
        $user = $request->user();
        $seesAll = Gate::allows('manageMembers', $workspace);

        $keys = McpToken::query()
            ->where('workspace_id', $workspace->id)
            ->where('audience', McpToken::API)
            ->active()
            ->when(! $seesAll, fn ($q) => $q->where('user_id', $user->id))
            ->with('user:id,name,email')
            ->latest('id')
            ->get();

        $requests = McpToolCall::query()
            ->where('workspace_id', $workspace->id)
            ->where('via', 'api')
            ->when(! $seesAll, fn ($q) => $q->where('user_id', $user->id))
            ->with('token:id,name')
            ->latest('id')
            ->limit(50)
            ->get();

        return Inertia::render('workspace/settings/Api', [
            'workspace' => $workspace->toPageProps(),
            'baseUrl' => rtrim((string) config('app.url'), '/').'/api/v1',
            'openapiUrl' => route('api.v1.openapi'),
            'guideUrl' => DocsUrl::page('guides', 'api'),
            'referenceUrl' => DocsUrl::page('reference', 'api-reference'),
            'rateLimit' => $workspace->isActive() ? $workspace->limitOf(Limit::ApiRequestsPerMinute) : null,
            'twoFactor' => $user->hasEnabledTwoFactorAuthentication(),
            'consoleUnlocked' => $console->isUnlocked($user),
            'canChange' => $workspace->allows($user, WorkspacePermission::ManageMachines),
            'seesAll' => $seesAll,
            'keys' => $keys->map(fn (McpToken $k) => [
                'id' => $k->id,
                'name' => $k->name,
                'prefix' => $k->token_prefix,
                'scopes' => $k->scopes,
                'owner' => ['id' => $k->user->id, 'name' => $k->user->name, 'you' => $k->user_id === $user->id],
                'lastUsedAt' => $k->last_used_at?->toIso8601String(),
                'createdAt' => $k->created_at?->toIso8601String(),
            ])->values(),
            'requests' => $requests->map(fn (McpToolCall $c) => [
                'id' => $c->id,
                'tool' => $c->tool,
                'status' => $c->status,
                'error' => $c->error,
                'key' => $c->token?->name,
                'durationMs' => $c->duration_ms,
                'at' => $c->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, ConsoleLock $console): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'access' => ['required', 'in:read,write,commands'],
            'code' => ['nullable', 'string', 'max:12'],
        ]);

        if ($data['access'] !== 'read' && ! $workspace->allows($user, WorkspacePermission::ManageMachines)) {
            return response()->json(['errors' => ['access' => ['Your role can only make read-only keys.']]], 422);
        }
        if ($data['access'] === 'commands' && ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['errors' => ['access' => ['Running commands needs two-factor authentication on your account.']]], 422);
        }
        if ($data['access'] === 'commands' && ($error = $console->confirm($user, $data['code'] ?? null)) !== null) {
            return response()->json(['errors' => ['code' => [$error]], 'code' => 'console_locked'], 422);
        }
        $count = McpToken::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)
            ->where('audience', McpToken::API)->active()->count();
        if ($count >= self::MAX_KEYS) {
            return response()->json(['errors' => ['name' => ['You have '.self::MAX_KEYS.' keys here. Revoke one you no longer use first.']]], 422);
        }

        $scopes = match ($data['access']) {
            'read' => [Scopes::READ],
            'write' => [Scopes::READ, Scopes::WRITE],
            default => Scopes::ALL,
        };
        [$key, $secret] = McpToken::issueKey($workspace, $user, trim($data['name']), $scopes, McpToken::API);
        Activity::record($workspace, 'api.key_create', $key->name, meta: ['scopes' => $key->scopes]);

        return response()->json(['id' => $key->id, 'secret' => $secret, 'scopes' => $key->scopes], 201);
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, McpToken $key): RedirectResponse
    {
        abort_unless($key->workspace_id === $workspace->id && $key->audience === McpToken::API, 404);
        abort_unless($key->user_id === $request->user()->id || Gate::allows('manageMembers', $workspace), 403);

        $key->revoke();
        Activity::record($workspace, 'api.key_revoke', $key->name, meta: ['owner' => $key->user_id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Revoked {$key->name}."]);

        return back();
    }
}
