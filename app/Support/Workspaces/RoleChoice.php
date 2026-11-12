<?php

namespace App\Support\Workspaces;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Support\Edition;

final class RoleChoice
{
    private function __construct(
        public readonly WorkspaceRole $role,
        public readonly ?WorkspaceCustomRole $custom,
    ) {}

    public static function parse(Workspace $workspace, mixed $value): self|string
    {
        $value = is_string($value) ? $value : '';

        if (preg_match('/^custom:(\d+)$/', $value, $m) === 1) {
            if (! $workspace->hasCustomRoles()) {
                return Edition::selfHosted() ? 'Custom roles need a ServerOS license.' : 'Custom roles are on Business.';
            }
            $custom = $workspace->customRoles()->find((int) $m[1]);

            return $custom === null ? 'That role no longer exists.' : new self(WorkspaceRole::Viewer, $custom);
        }

        $role = WorkspaceRole::tryFrom($value);
        if ($role === null || ! in_array($role, WorkspaceRole::assignable(), true)) {
            return 'Pick a role.';
        }
        if ($role->isStaffRole() && ! $workspace->hasStaffRoles()) {
            return 'Member and Viewer roles are on Pro and Business.';
        }

        return new self($role, null);
    }

    public static function of(WorkspaceRole $role, ?WorkspaceCustomRole $custom): self
    {
        return new self($custom !== null ? WorkspaceRole::Viewer : $role, $custom);
    }

    public function matches(string $role, ?int $customRoleId): bool
    {
        return $this->role->value === $role && $this->custom?->id === $customRoleId;
    }

    /** @return list<WorkspacePermission> */
    public function permissions(): array
    {
        return Workspace::permissionsFor($this->role, $this->custom);
    }

    public function label(): string
    {
        return $this->custom->name ?? $this->role->label();
    }

    public static function value(string $role, ?int $customRoleId): string
    {
        return $customRoleId !== null ? "custom:{$customRoleId}" : $role;
    }
}
