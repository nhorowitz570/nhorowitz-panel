<?php

namespace App\Support\Licensing;

use App\Enums\Feature;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Edition;
use App\Support\Entitlements\SelfHostedEntitlements;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\SettingsItem;

final class CommunityLicense
{
    public const string OFFICIAL_IMAGE = 'ghcr.io/serveroshq/panel';

    public static function register(Extensions $extensions): void
    {
        $extensions->addSettingsItem(new SettingsItem(
            key: 'community-license',
            label: 'License',
            icon: 'key',
            path: '/settings/license',
            group: 'workspace',
            component: 'workspace/settings/CommunityLicense',
            keywords: 'serveros business license key community edition official image sso scim roles',
            visible: fn (Workspace $workspace, ?User $user) => self::shown() && $user?->can('manageSettings', $workspace) === true,
        ));
    }

    public static function shown(): bool
    {
        return Edition::selfHosted() && ! app(Extensions::class)->hasSettingsItem('license');
    }

    /** @return list<string> */
    public static function paidFeatures(): array
    {
        return array_values(array_map(
            fn (Feature $feature) => $feature->value,
            array_filter(Feature::cases(), fn (Feature $feature) => ! in_array($feature, SelfHostedEntitlements::FREE_FEATURES, true)),
        ));
    }
}
