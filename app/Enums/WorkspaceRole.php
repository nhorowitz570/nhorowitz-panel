<?php

namespace App\Enums;

use App\Support\Edition;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        if (Edition::withoutBilling() && in_array($this, [self::Owner, self::Admin], true)) {
            return $this === self::Owner ? 'Full access, including deleting the workspace.' : 'Everything except deleting the workspace.';
        }

        return match ($this) {
            self::Owner => 'Full access, including billing.',
            self::Admin => 'Everything except billing.',
            self::Member => 'Works with machines and services.',
            self::Viewer => 'Read-only access.',
        };
    }

    /** @return list<WorkspacePermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => WorkspacePermission::cases(),
            self::Admin => [
                WorkspacePermission::ManageSettings,
                WorkspacePermission::ManageMembers,
                WorkspacePermission::ManageMachines,
            ],
            self::Member => [WorkspacePermission::ManageMachines],
            self::Viewer => [],
        };
    }

    public function can(WorkspacePermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function isStaffRole(): bool
    {
        return in_array($this, [self::Member, self::Viewer], true);
    }

    /** @return list<self> */
    public static function assignable(): array
    {
        return [self::Admin, self::Member, self::Viewer];
    }

    /**
     * @return array{value: string, label: string, description: string, staff: bool, permissions: list<string>}
     */
    public function toPageProps(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'staff' => $this->isStaffRole(),
            'permissions' => array_values(array_map(fn (WorkspacePermission $permission) => $permission->value, array_filter($this->permissions(), fn (WorkspacePermission $permission) => in_array($permission, WorkspacePermission::offered(), true)))),
        ];
    }
}
