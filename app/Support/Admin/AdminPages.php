<?php

namespace App\Support\Admin;

use App\Support\Extensions\AdminNavItem;
use App\Support\Extensions\Extensions;

final class AdminPages
{
    public static function register(Extensions $extensions): void
    {
        $extensions->addAdminNavItem(new AdminNavItem(
            key: 'pulse', label: 'Pulse', icon: 'heart-pulse', path: '/admin/pulse',
            group: 'platform', after: 'system', external: true,
        ));
    }
}
