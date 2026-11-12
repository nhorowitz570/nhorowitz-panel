<?php

namespace App\Http\Controllers;

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Edition;
use App\Support\Logs\LogStore;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceLogStoreController extends Controller
{
    private const FIELDS = ['host', 'port', 'database', 'username', 'password'];

    public function show(Request $request, Workspace $workspace): Response
    {
        $store = self::store($workspace);

        return Inertia::render('workspace/settings/LogStorage', [
            'workspace' => $workspace->toPageProps(),
            'canManage' => Gate::allows('manageSettings', $workspace),
            'planAllows' => $workspace->hasOwnLogStorage(),
            'egressIp' => config('serveros.egress_ip'),
            'retentionDays' => ($workspace->effectivePlan() ?? Plan::Business)->logRetentionDays(),
            'store' => $store === null ? null : [
                'host' => $store->host,
                'port' => $store->port,
                'database' => $store->database,
                'username' => $store->username,
                'hasPassword' => $store->password !== '',
                'enabled' => $store->enabled,
                'prune' => $store->prune,
                'status' => $store->status,
                'lastError' => $store->last_error,
                'lastOkAt' => $store->last_ok_at?->toIso8601String(),
            ],
        ]);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeChange($workspace);
        $store = self::store($workspace);
        $data = $this->connection($request, $store);
        $prune = $request->validate(['prune' => ['required', 'boolean']])['prune'];

        $store ??= new WorkspaceLogStore(['workspace_id' => $workspace->id, 'driver' => 'pgsql', 'enabled' => false]);
        $store->fill([...$data, 'prune' => (bool) $prune]);

        $moved = $store->isDirty(self::FIELDS);
        if ($store->exists && $store->enabled && $moved) {
            if (($problem = LogStore::test($store)) !== null) {
                throw ValidationException::withMessages(['connection' => $problem]);
            }
            LogStore::install($store);
            $store->fill(['status' => 'ok', 'last_error' => null, 'last_ok_at' => now()]);
        }

        if (! $store->isDirty()) {
            AuditContext::nothingChanged();

            return back();
        }

        $created = ! $store->exists;
        $store->save();
        LogStore::flush();

        Activity::record($workspace, 'workspace.log_storage_update', $store->host, meta: array_filter([
            'added' => $created ?: null,
            'connection' => ! $created && $moved ?: null,
            'prune' => $store->wasChanged('prune') || $created ? $store->prune : null,
        ], fn ($v) => $v !== null));

        return back();
    }

    #[Audited]
    public function test(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeChange($workspace);
        $store = self::store($workspace);

        if ($request->filled('host')) {
            $trial = ($store?->replicate() ?? new WorkspaceLogStore(['driver' => 'pgsql']))->fill($this->connection($request, $store));
            $trial->workspace_id = $workspace->id;
        } elseif ($store !== null) {
            $trial = $store;
        } else {
            throw ValidationException::withMessages(['host' => __('Enter the database’s host first.')]);
        }

        $problem = LogStore::test($trial);
        if ($trial === $store) {
            $problem === null ? LogStore::markOk($workspace) : $store->forceFill(['status' => 'unreachable', 'last_error' => $problem])->save();
        }
        AuditContext::nothingChanged();

        if ($problem !== null) {
            throw ValidationException::withMessages(['connection' => $problem]);
        }

        return back();
    }

    #[Audited]
    public function enable(Workspace $workspace): RedirectResponse
    {
        $this->authorizeChange($workspace);
        $store = self::store($workspace) ?? throw ValidationException::withMessages(['host' => __('Save the connection first.')]);

        if ($store->enabled) {
            AuditContext::nothingChanged();

            return back();
        }
        if (($problem = LogStore::test($store)) !== null) {
            $store->forceFill(['status' => 'unreachable', 'last_error' => $problem])->save();
            throw ValidationException::withMessages(['connection' => $problem]);
        }

        LogStore::install($store);
        $store->forceFill(['enabled' => true, 'status' => 'ok', 'last_error' => null, 'last_ok_at' => now()])->save();
        LogStore::flush();

        Activity::record($workspace, 'workspace.log_storage_enable', $store->host);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New log lines now go to your database.')]);

        return back();
    }

    #[Audited]
    public function disable(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $store = self::store($workspace);

        if ($store === null || ! $store->enabled) {
            AuditContext::nothingChanged();

            return back();
        }

        $store->forceFill(['enabled' => false])->save();
        LogStore::flush();

        Activity::record($workspace, 'workspace.log_storage_disable', $store->host);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('New log lines go to ServerOS again.')]);

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $store = self::store($workspace);

        if ($store === null) {
            AuditContext::nothingChanged();

            return back();
        }
        if ($store->enabled) {
            throw ValidationException::withMessages(['connection' => __('Turn it off before removing it.')]);
        }

        $store->delete();
        LogStore::flush();

        Activity::record($workspace, 'workspace.log_storage_remove', $store->host);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed the connection. Lines already in that database are still there.')]);

        return back();
    }

    private static function store(Workspace $workspace): ?WorkspaceLogStore
    {
        return WorkspaceLogStore::query()->where('workspace_id', $workspace->id)->first();
    }

    private function authorizeChange(Workspace $workspace): void
    {
        Gate::authorize('manageSettings', $workspace);
        if (! $workspace->hasOwnLogStorage()) {
            throw ValidationException::withMessages(['host' => Edition::selfHosted() ? __('Logs in your own database need a ServerOS license.') : __('Logs in your own database come with Business.')]);
        }
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    private function connection(Request $request, ?WorkspaceLogStore $store): array
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/'],
            'username' => ['required', 'string', 'max:63'],
            'password' => [$store === null ? 'required' : 'nullable', 'string', 'max:200'],
        ], [
            'host.regex' => __('Use the database’s host name or IP address, like db.example.com.'),
            'database.regex' => __('Use the database’s name, like defaultdb: letters, numbers, dots, dashes and underscores.'),
        ]);

        $host = strtolower(trim($data['host'], " []\t\n"));
        if (app(HostResolver::class)->resolve($host) === []) {
            throw ValidationException::withMessages(['host' => __('That host doesn’t resolve to a public address.')]);
        }

        $port = (int) $data['port'];
        $username = trim($data['username']);
        $password = filled($data['password'] ?? null) ? (string) $data['password'] : null;
        $sameServer = $store !== null && $host === $store->host && $port === (int) $store->port && $username === $store->username;
        if ($password === null && ! $sameServer) {
            throw ValidationException::withMessages(['password' => __('Enter the password again when you change the host, port or user.')]);
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => trim($data['database']),
            'username' => $username,
            'password' => $password ?? (string) $store->password,
        ];
    }
}
