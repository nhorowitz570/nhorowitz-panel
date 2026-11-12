<?php

namespace App\Actions\Workspaces;

use App\Events\WorkspaceCreated;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Edition;
use App\Support\Extensions\TrialCards;
use App\Support\WorkspaceSlugs;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CreateWorkspace
{
    public function __construct(private WorkspaceSlugs $slugs) {}

    public function create(User $owner, ?string $slug = null, ?string $name = null): Workspace
    {
        $slug = filled($slug) ? $slug : $this->slugs->generate($name ?? $owner->name, $owner->email);
        $trial = self::getsTrial($owner);

        $workspace = new Workspace([
            'name' => $name ?? Str::headline($slug),
            'slug' => $slug,
            'trial_ends_at' => $trial ? now()->addDays(Workspace::TRIAL_DAYS) : null,
        ]);
        $actor = Auth::user();
        $selfMade = $actor === null || $actor->getAuthIdentifier() === $owner->id;
        $first = Edition::selfHosted() ? Workspace::query()->where('owner_id', $owner->id)->doesntExist() : $trial;
        $workspace->onboarded_at = $first && $selfMade ? null : now();
        if ($trial && $selfMade) {
            app(TrialCards::class)->assign($owner, $workspace);
        }
        $workspace->owner()->associate($owner);
        $workspace->save();

        if ($trial) {
            $owner->forceFill(['trial_used_at' => now()])->saveQuietly();
        }

        $workspace->members()->attach($owner, ['role' => 'owner']);
        if (request()->user() === null || AuditContext::isRequestUser($owner)) {
            Activity::record($workspace, 'workspace.create', $workspace->name, user: $owner);
        } else {
            Activity::system($workspace, 'workspace.create', $workspace->name);
        }

        event(new WorkspaceCreated($workspace, $owner));

        return $workspace->refresh();
    }

    public static function getsTrial(User $owner): bool
    {
        return $owner->trial_used_at === null && ! Edition::selfHosted();
    }
}
