<?php

namespace App\Enums;

use App\Support\Edition;

enum WorkspacePermission: string
{
    case ManageBilling = 'manage-billing';
    case ManageSettings = 'manage-settings';
    case ManageMembers = 'manage-members';
    case ManageMachines = 'manage-machines';

    /** @return list<self> */
    public static function offered(): array
    {
        return Edition::withoutBilling() ? [self::ManageSettings, self::ManageMembers, self::ManageMachines] : self::cases();
    }

    public function label(): string
    {
        return match ($this) {
            self::ManageBilling => 'Change plan and billing',
            self::ManageSettings => 'Edit workspace settings',
            self::ManageMembers => 'Invite and manage members',
            self::ManageMachines => 'Connect and manage machines',
        };
    }
}
