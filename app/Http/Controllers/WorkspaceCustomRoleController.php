<?php

namespace App\Http\Controllers;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Support\Audit\Audited;
use App\Support\Edition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkspaceCustomRoleController extends Controller
{
    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $this->validated($request, $workspace);
        $role = $workspace->customRoles()->create($data);
        Activity::record($workspace, 'role.create', $role->name);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added the {$role->name} role."]);

        return back();
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, WorkspaceCustomRole $role): RedirectResponse
    {
        abort_unless($role->workspace_id === $workspace->id, 404);
        $role->update($this->validated($request, $workspace, $role));
        Activity::record($workspace, 'role.update', $role->name);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved the {$role->name} role."]);

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, WorkspaceCustomRole $role): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($role->workspace_id === $workspace->id, 404);
        if (! $workspace->canGrant($request->user(), Workspace::permissionsFor(WorkspaceRole::Viewer, $role))) {
            throw ValidationException::withMessages(['role' => 'This role has access you don’t, so you can’t delete it.']);
        }

        $role->delete();
        Activity::record($workspace, 'role.delete', $role->name);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Deleted the {$role->name} role. Its members are Viewers now."]);

        return back();
    }

    /**
     * @return array{name: string, description: string|null, permissions: list<string>}
     */
    private function validated(Request $request, Workspace $workspace, ?WorkspaceCustomRole $role = null): array
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($workspace->hasCustomRoles(), 403, Edition::selfHosted() ? 'Custom roles need a ServerOS license.' : 'Custom roles are on Business.');

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:40',
                Rule::notIn(['Owner', 'Admin', 'Member', 'Viewer', 'owner', 'admin', 'member', 'viewer']),
                Rule::unique('workspace_custom_roles')->where('workspace_id', $workspace->id)->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:160'],
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::enum(WorkspacePermission::class)],
        ], [
            'name.not_in' => 'That name is taken by a built-in role.',
            'name.unique' => 'There’s already a role with that name.',
        ]);

        if ($role !== null && ! $workspace->canGrant($request->user(), Workspace::permissionsFor(WorkspaceRole::Viewer, $role))) {
            throw ValidationException::withMessages(['permissions' => 'This role has access you don’t, so you can’t change it.']);
        }
        $requested = array_values(array_map(fn (string $p) => WorkspacePermission::from($p), $data['permissions']));
        if (! $workspace->canGrant($request->user(), $requested)) {
            throw ValidationException::withMessages(['permissions' => 'You can’t give a role more access than your own.']);
        }

        return [
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'permissions' => array_values(array_unique($data['permissions'])),
        ];
    }
}
