import { trans } from 'laravel-vue-i18n';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Component, ComputedRef } from 'vue';
import {
    SolarBuilding2,
    SolarCode,
    SolarDatabase,
    SolarGlobe,
    SolarLockKeyhole,
    SolarPalette,
    SolarPulse,
    SolarServerCog,
    SolarSparkles,
    SolarUserRound,
    SolarUsers,
} from '@/lib/solarIcons';
import { extensionIcon } from '@/lib/extensionIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import members from '@/routes/workspace/members';
import settings from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

export type Section = {
    label: string;
    anchor: string;
    keywords?: string;
};

export type NavItem = {
    key: string;
    label: string;
    icon: Component;
    href: string;
    current: boolean;
    keywords: string;
    sections: Section[];
};

export type Result = {
    key: string;
    label: string;
    page: string | null;
    icon: Component;
    href: string;
};

type ExtensionSettingsItem = {
    key: string;
    label: string;
    icon: string;
    path: string;
    group: string;
    component: string;
    keywords: string;
    sections: Section[];
};

export type Group = { key: string; label: string; items: NavItem[] };

export function useSettingsNav(): ComputedRef<Group[]> {
    const page = usePage<{
        workspace: Workspace;
        extensions?: { settings: ExtensionSettingsItem[] } | null;
    }>();

    return computed<Group[]>(() =>
        withExtensions(
            ownGroups(page.props.workspace.slug, page.component),
            page.props.extensions?.settings ?? [],
            page.component,
        ),
    );
}

function withExtensions(
    own: Group[],
    extensions: ExtensionSettingsItem[],
    component: string,
): Group[] {
    return own.map((group) => ({
        ...group,
        items: [
            ...group.items,
            ...extensions
                .filter((extension) => extension.group === group.key)
                .map((extension) => ({
                    label: extension.label,
                    icon: extensionIcon(extension.icon),
                    href: extension.path,
                    key: extension.component,
                    current: component === extension.component,
                    keywords: extension.keywords,
                    sections: extension.sections,
                })),
        ],
    }));
}

function ownGroups(slug: string, component: string): Group[] {
    const item = (
        label: string,
        icon: Component,
        href: string,
        name: string,
        keywords: string,
        sections: Section[] = [],
    ): NavItem => ({
        label,
        icon,
        href,
        key: `workspace/settings/${name}`,
        current: component === `workspace/settings/${name}`,
        keywords,
        sections,
    });

    return [
        {
            key: 'workspace',
            label: trans('Workspace'),
            items: [
                item(
                    trans('General'),
                    SolarBuilding2,
                    onWorkspace(settings.general(slug)).url,
                    'General',
                    'name logo address',
                    [
                        {
                            label: trans('Alerts'),
                            anchor: 'alerts',
                            keywords:
                                'notifications email webhook slack discord mute',
                        },
                        {
                            label: 'GitHub',
                            anchor: 'github',
                            keywords: 'git repositories repos deploy connect',
                        },
                        {
                            label: trans('Danger zone'),
                            anchor: 'danger-zone',
                            keywords: 'delete workspace remove',
                        },
                    ],
                ),
                item(
                    trans('Members'),
                    SolarUsers,
                    onWorkspace(members.index(slug)).url,
                    'Members',
                    'team people users',
                    [
                        {
                            label: trans('Invite people'),
                            anchor: 'invite',
                            keywords: 'add teammate email',
                        },
                        {
                            label: trans('Pending invites'),
                            anchor: 'invites',
                            keywords: 'invitations',
                        },
                        {
                            label: trans('Roles'),
                            anchor: 'roles',
                            keywords: 'permissions admin owner access',
                        },
                    ],
                ),
                item(
                    trans('Domains'),
                    SolarGlobe,
                    onWorkspace(settings.domains(slug)).url,
                    'Domains',
                    'custom domain dns cname ssl',
                ),
                item(
                    trans('Status page'),
                    SolarPulse,
                    onWorkspace(settings.statusPage(slug)).url,
                    'StatusPage',
                    'public uptime incidents',
                ),
            ],
        },
        {
            key: 'machines',
            label: trans('Machines'),
            items: [
                item(
                    trans('Machine policies'),
                    SolarServerCog,
                    onWorkspace(settings.machinePolicies(slug)).url,
                    'MachinePolicies',
                    'daemon updates releases collect metrics defaults groups',
                ),
                item(
                    trans('Log storage'),
                    SolarDatabase,
                    onWorkspace(settings.logStorage(slug)).url,
                    'LogStorage',
                    'logs s3 bucket retention',
                ),
            ],
        },
        {
            key: 'developers',
            label: trans('Developers'),
            items: [
                item(
                    'API',
                    SolarCode,
                    onWorkspace(settings.api(slug)).url,
                    'Api',
                    'rest tokens',
                    [
                        {
                            label: trans('API keys'),
                            anchor: 'api-keys',
                            keywords: 'tokens',
                        },
                        {
                            label: trans('Recent requests'),
                            anchor: 'requests',
                        },
                    ],
                ),
                item(
                    trans('AI and MCP'),
                    SolarSparkles,
                    onWorkspace(settings.mcp(slug)).url,
                    'Mcp',
                    'claude chatgpt cursor assistant',
                    [
                        {
                            label: trans('Connect an AI app'),
                            anchor: 'connect',
                        },
                        {
                            label: trans('AI tool calls'),
                            anchor: 'tool-calls',
                            keywords: 'usage limit',
                        },
                        {
                            label: trans('Keys and connected apps'),
                            anchor: 'keys',
                            keywords: 'tokens',
                        },
                    ],
                ),
            ],
        },
        {
            key: 'account',
            label: trans('Your account'),
            items: [
                item(
                    trans('Profile'),
                    SolarUserRound,
                    onWorkspace(settings.profile(slug)).url,
                    'Profile',
                    'name avatar picture',
                    [
                        {
                            label: trans('Your emails from this workspace'),
                            anchor: 'emails',
                            keywords: 'notifications digest unsubscribe',
                        },
                    ],
                ),
                item(
                    trans('Appearance'),
                    SolarPalette,
                    onWorkspace(settings.appearance(slug)).url,
                    'Appearance',
                    'theme dark light mode navigation sidebar top bar layout',
                ),
                item(
                    trans('Security'),
                    SolarLockKeyhole,
                    onWorkspace(settings.security(slug)).url,
                    'Security',
                    'login sign in',
                    [
                        {
                            label: trans('Workspace security'),
                            anchor: 'workspace-security',
                            keywords: 'require two-factor 2fa',
                        },
                        {
                            label: trans('Password'),
                            anchor: 'password',
                        },
                        {
                            label: trans('Connected accounts'),
                            anchor: 'connected-accounts',
                            keywords: 'google github sign in',
                        },
                        {
                            label: trans('Two-factor authentication'),
                            anchor: 'two-factor',
                            keywords: '2fa authenticator otp recovery codes',
                        },
                        {
                            label: trans('Passkeys'),
                            anchor: 'passkeys',
                            keywords: 'touch id face id security key',
                        },
                        {
                            label: trans('Signed-in devices'),
                            anchor: 'devices',
                            keywords: 'sessions sign out',
                        },
                        {
                            label: trans('Recent security activity'),
                            anchor: 'security-activity',
                            keywords: 'log history',
                        },
                    ],
                ),
            ],
        },
    ];
}
