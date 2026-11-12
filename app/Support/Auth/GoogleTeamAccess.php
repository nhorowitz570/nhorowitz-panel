<?php

namespace App\Support\Auth;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Models\WorkspaceEmailDomain;
use App\Models\WorkspaceJoinRequest;
use App\Notifications\WorkspaceJoinRequested;
use App\Support\Extensions\Extensions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Contracts\User as ProviderUser;

final class GoogleTeamAccess
{
    public const string VIA = 'auth.via';

    public const string HD = 'auth.google_hd';

    public const string PENDING_HD = 'login.google_hd';

    public static function verifiedDomain(ProviderUser $account): ?string
    {
        $raw = method_exists($account, 'getRaw') ? $account->getRaw() : [];
        if (($raw['email_verified'] ?? $raw['verified_email'] ?? false) !== true) {
            return null;
        }

        $hd = $raw['hd'] ?? null;
        $email = strtolower(trim((string) $account->getEmail()));
        $domain = str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '';
        if (! is_string($hd) || $domain === '') {
            return null;
        }

        return strtolower(trim($hd)) === $domain ? $domain : null;
    }

    public function signedIn(Request $request, User $user, string $method): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $pending = $session->pull(self::PENDING_HD);
        $session->put(self::VIA, $method);

        foreach (app(Extensions::class)->signInHooks() as $hooks) {
            $hooks->signedIn($request, $user, $method);
        }

        $hd = $method === 'google' && is_string($pending) && $pending !== '' ? $pending : null;
        if ($hd === null) {
            $session->forget(self::HD);

            return;
        }
        $session->put(self::HD, $hd);

        $outcome = $this->join($user, $hd);
        if ($outcome === null || $outcome[0] === 'member' || $session->has('url.intended')) {
            return;
        }

        $session->put('url.intended', $outcome[0] === 'joined'
            ? $outcome[1]->url()
            : rtrim((string) config('app.url'), '/').route('join-requests.show', absolute: false));
    }

    /** @return array{0: 'joined'|'asked'|'waiting'|'member', 1: Workspace}|null */
    public function join(User $user, string $domain): ?array
    {
        $workspace = WorkspaceEmailDomain::query()
            ->where('domain', strtolower($domain))
            ->whereNotNull('verified_at')
            ->first()?->workspace;

        if ($workspace === null || $workspace->domain_join === 'off' || ! $workspace->hasGoogleTeamAccess()) {
            return null;
        }
        if ($workspace->hasMember($user)) {
            return ['member', $workspace];
        }
        if ($workspace->joinRequests()->where('user_id', $user->id)->where('state', WorkspaceJoinRequest::DENIED)->exists()) {
            return null;
        }

        if ($workspace->domain_join === 'auto') {
            $custom = $workspace->domain_join_custom_role_id === null ? null : WorkspaceCustomRole::query()->find($workspace->domain_join_custom_role_id);
            $role = $custom !== null ? 'viewer' : $workspace->domain_join_role;

            DB::transaction(function () use ($workspace, $user, $role, $custom): void {
                $workspace->members()->attach($user, ['role' => $role, 'custom_role_id' => $custom?->id]);
                $workspace->joinRequests()->where('user_id', $user->id)->pending()->get()->each->decide(WorkspaceJoinRequest::APPROVED, null);
            });
            Activity::record($workspace, 'member.join', user: $user, meta: [
                'role' => $custom->name ?? (WorkspaceRole::tryFrom($role)?->label() ?? $role),
                'via' => 'google_domain',
                'domain' => $domain,
            ]);

            return ['joined', $workspace];
        }

        if ($workspace->joinRequests()->where('user_id', $user->id)->pending()->exists()) {
            return ['waiting', $workspace];
        }

        try {
            $workspace->joinRequests()->create(['user_id' => $user->id, 'domain' => $domain]);
        } catch (UniqueConstraintViolationException) {
            return ['waiting', $workspace];
        }
        Activity::record($workspace, 'member.request', user: $user, meta: ['domain' => $domain]);

        $admins = $workspace->members()->get()->filter(fn (User $member) => $workspace->allows($member, WorkspacePermission::ManageMembers));
        Notification::send($admins, new WorkspaceJoinRequested($workspace, $user, $domain));

        return ['asked', $workspace];
    }
}
