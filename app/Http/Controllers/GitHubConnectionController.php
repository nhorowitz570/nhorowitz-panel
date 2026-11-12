<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\GitHubInstallation;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\GitHub\GitHubApp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class GitHubConnectionController extends Controller
{
    private const STATE_TTL_SECONDS = 3600;

    public function __construct(private readonly GitHubApp $github) {}

    public function connect(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless(GitHubApp::enabled(), 404);

        $state = Crypt::encryptString((string) json_encode([
            'workspace' => $workspace->id,
            'user' => $request->user()->id,
            'at' => now()->getTimestamp(),
        ]));
        $request->session()->put('github_connect_state', $state);

        return Inertia::location($this->github->installUrl($state));
    }

    public function setup(Request $request): RedirectResponse
    {
        abort_unless(GitHubApp::enabled(), 404);

        $workspace = $this->workspaceFromState($request);
        if ($workspace === null) {
            return redirect()->route('workspaces.index')->with('status', 'Connect GitHub from a workspace’s settings, so ServerOS knows which workspace it’s for.');
        }
        $back = $workspace->url().'/settings/general';

        if ($request->query('setup_action') === 'request') {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to your organisation’s owners to approve. Once they do, connect GitHub again.']);

            return redirect()->away($back);
        }

        $installationId = (int) $request->query('installation_id');
        $code = (string) $request->query('code');
        $installer = $installationId > 0 && $code !== '' ? $this->github->confirmInstaller($code, $installationId) : null;
        if ($installer === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'GitHub didn’t confirm you manage that installation. An owner of the GitHub account, or an admin of every repository it shares, needs to connect it.']);

            return redirect()->away($back);
        }
        $login = $installer['login'];

        $workspace->githubInstallations()->updateOrCreate(['installation_id' => $installationId], [
            'account_login' => $login,
            'account_type' => $installer['type'],
            'repository_ids' => $installer['repository_ids'],
            'connected_by' => $request->user()->id,
        ]);
        Activity::record($workspace, 'github.connected', $login);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Connected GitHub ({$login}). Its private repositories you shared can be deployed now."]);

        return redirect()->away($back);
    }

    #[Audited]
    public function destroy(Workspace $workspace, GitHubInstallation $installation): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($installation->workspace_id === $workspace->id, 404);

        $installation->delete();
        Activity::record($workspace, 'github.disconnected', $installation->account_login);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Disconnected {$installation->account_login}. To take the app off GitHub too, uninstall it in GitHub’s settings."]);

        return back();
    }

    private function workspaceFromState(Request $request): ?Workspace
    {
        try {
            $state = json_decode(Crypt::decryptString((string) ($request->query('state') ?: $request->session()->pull('github_connect_state'))), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($state) || ! is_int($state['workspace'] ?? null)
            || ($state['user'] ?? null) !== $request->user()?->id
            || now()->getTimestamp() - (int) ($state['at'] ?? 0) > self::STATE_TTL_SECONDS) {
            return null;
        }

        $workspace = Workspace::query()->find($state['workspace']);

        return $workspace !== null && $request->user()->can('manageMachines', $workspace) ? $workspace : null;
    }
}
