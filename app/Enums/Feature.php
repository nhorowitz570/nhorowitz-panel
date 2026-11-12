<?php

namespace App\Enums;

enum Feature: string
{
    case StaffRoles = 'staff_roles';

    case CustomRoles = 'custom_roles';

    case Sso = 'sso';

    case Scim = 'scim';

    case CustomDomains = 'custom_domains';

    case OwnLogStorage = 'own_log_storage';

    case FleetStatusPage = 'fleet_status_page';

    case StatusPages = 'status_pages';

    case Automations = 'automations';
}
