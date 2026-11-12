<?php

namespace App\Support\Mcp;

use App\Enums\WorkspacePermission;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\McpOAuthClient;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\ConsoleLock;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class WorkspaceAudience extends OAuthAudience
{
    public function key(): string
    {
        return McpToken::WORKSPACE;
    }

    public function scopes(): array
    {
        return Scopes::ALL;
    }

    public function describe(): array
    {
        return Scopes::describe();
    }

    public function normalise(string|array|null $requested): array
    {
        return Scopes::normalise($requested);
    }

    public function claims(mixed $resource): bool
    {
        return $resource === null || (is_string($resource) && OAuth::workspaceFor($resource) !== null);
    }

    public function consent(Request $request, User $user, mixed $resource): OAuthConsent|Response
    {
        $workspaces = $user->workspaces()->orderBy('name')->get();
        if ($resource !== null) {
            $workspace = OAuth::workspaceFor(is_string($resource) ? $resource : null);
            if ($workspace === null || ! $workspaces->contains('id', $workspace->id)) {
                return OAuthPage::problem("You’re signed in as {$user->email}, who isn’t a member of the workspace this app asked for. Sign in as someone who is, or ask to be invited.");
            }
            if (! self::signedInAsRequired($workspace, $user)) {
                return OAuthPage::problem("{$workspace->name} asks you to sign in with Google using your company account. Sign out, sign in that way, then connect again.");
            }
            $workspaces = $workspaces->where('id', $workspace->id)->values();
        }
        if ($workspaces->isEmpty()) {
            return OAuthPage::problem('You’re not in any workspace yet. Create one first, then connect again.');
        }
        $workspaces = $workspaces->filter(fn (Workspace $w) => self::signedInAsRequired($w, $user))->values();
        if ($workspaces->isEmpty()) {
            return OAuthPage::problem('Your workspaces ask you to sign in with Google using your company account. Sign out, sign in that way, then connect again.');
        }

        $requested = Scopes::normalise((string) $request->query('scope', implode(' ', Scopes::ALL)));

        return new OAuthConsent(
            pending: [
                'scopes' => $requested,
                'workspace' => $resource !== null ? $workspaces->first()->id : null,
            ],
            props: [
                'workspaces' => $workspaces->map(fn (Workspace $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'host' => $w->host(),
                    'role' => $w->roleOf($user)?->label(),
                    'canChange' => $w->allows($user, WorkspacePermission::ManageMachines),
                ])->values(),
                'fixedWorkspace' => $resource !== null,
                'requested' => $requested,
                'scopes' => Scopes::describe(),
                'twoFactor' => $user->hasEnabledTwoFactorAuthentication(),
                'account' => $user->email,
            ],
        );
    }

    public function approve(User $user, array $pending, array $choices): OAuthGrant|Response
    {
        $workspace = $user->workspaces()->whereKey($pending['workspace'] ?? $choices['workspace'] ?? 0)->first();
        if ($workspace === null) {
            return back()->withErrors(['workspace' => 'Choose a workspace you’re a member of.']);
        }
        if (! $workspace->satisfiesTwoFactor($user, request())) {
            return back()->withErrors(['workspace' => $user->passkeys()->exists()
                ? "{$workspace->name} requires two-factor sign-in. Confirm it’s you with your passkey in ServerOS, or set up an authenticator app."
                : "{$workspace->name} requires two-factor sign-in; set it up first."]);
        }
        if (! self::signedInAsRequired($workspace, $user)) {
            return back()->withErrors(['workspace' => "{$workspace->name} asks you to sign in with Google using your company account."]);
        }

        $scopes = [Scopes::READ];
        if (($choices['write'] ?? false) && in_array(Scopes::WRITE, $pending['scopes'], true)) {
            $scopes[] = Scopes::WRITE;
            if (($choices['commands'] ?? false) && in_array(Scopes::COMMANDS, $pending['scopes'], true)) {
                if (! $user->hasEnabledTwoFactorAuthentication()) {
                    return back()->withErrors(['commands' => 'Running commands needs two-factor authentication on your account.']);
                }
                if (! app(ConsoleLock::class)->unlockedWith($user, $choices['code'] ?? null)) {
                    return back()->withErrors(['commands' => 'Enter the code from your authenticator app to allow running commands.']);
                }
                $scopes[] = Scopes::COMMANDS;
            }
        }

        return new OAuthGrant($scopes, OAuth::resource($workspace), $workspace);
    }

    private static function signedInAsRequired(Workspace $workspace, User $user): bool
    {
        $session = request()->hasSession() ? request()->session() : null;
        $via = $session?->get(GoogleTeamAccess::VIA);
        $hd = $session?->get(GoogleTeamAccess::HD);

        return $workspace->satisfiesGoogleSignIn($user, is_string($via) ? $via : null, is_string($hd) ? $hd : null);
    }

    public function approved(User $user, McpOAuthClient $client, OAuthGrant $grant): void
    {
        if ($grant->workspace !== null) {
            Activity::record($grant->workspace, 'mcp.connect', $client->name, user: $user, meta: ['scopes' => $grant->scopes]);
        }
    }
}
